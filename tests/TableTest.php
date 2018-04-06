<?php
namespace Atlas\Table;

use Atlas\Table\Exception;
use Atlas\Testing\CompositeDataSource\Course\CourseRow;
use Atlas\Testing\CompositeDataSource\Course\CourseTable;
use Atlas\Testing\CompositeDataSource\SqliteFixture as CompositeFixture;
use Atlas\Testing\DataSource\Employee\EmployeeRow;
use Atlas\Testing\DataSource\Employee\EmployeeTable;
use Atlas\Testing\DataSource\SqliteFixture;
use PDO;

class TableTest extends \PHPUnit\Framework\TestCase
{
    protected $table;

    protected $tableLocator;

    protected function setUp()
    {
        $connection = (new SqliteFixture())->exec();
        (new CompositeFixture($connection))->exec();

        $this->tableLocator = TableLocator::new($connection);
        $this->table = $this->tableLocator->get(EmployeeTable::CLASS);
    }

    public function testUpdateOnChangedPrimaryKey()
    {
        $row = $this->table->fetchRow(1);
        $row->id = 2;
        $this->expectException(
            Exception::CLASS,
            "Primary key value for 'id' changed from '1' to '2'"
        );
        $this->table->updateRow($row);
    }

    public function testFetchRow()
    {
        $expect = [
            'id' => '1',
            'name' => 'Anna',
            'building' => '1',
            'floor' => '1',
        ];

        $row = $this->table->fetchRow(1);
        $this->assertInstanceOf(EmployeeRow::CLASS, $row);
        $this->assertSame($expect, $row->getArrayCopy());

        // fetch failure
        $actual = $this->table->fetchRow(-1);
        $this->assertNull($actual);
    }

    public function fetchRow_compositeKey()
    {
        $table = $this->tableLocator->get(CourseTable::CLASS);

        $expect = [
            'course_subject' => 'MATH',
            'course_number' => '100',
            'title' => 'Algebra',
        ];

        $actual = $table->fetchRow([
            'course_subject' => 'MATH',
            'course_number' => '100'
        ]);

        $this->assertSame($expect, $actual->getArrayCopy());
    }

    public function testFetchRows()
    {
        $expect = [
            [
                'id' => '1',
                'name' => 'Anna',
                'building' => '1',
                'floor' => '1',
            ],
            [
                'id' => '2',
                'name' => 'Betty',
                'building' => '1',
                'floor' => '2',
            ],
            [
                'id' => '3',
                'name' => 'Clara',
                'building' => '1',
                'floor' => '3',
            ],
        ];

        $actual = $this->table->fetchRows([1, 2, 3]);
        $this->assertCount(3, $actual);
        $this->assertInstanceOf(EmployeeRow::CLASS, $actual[0]);
        $this->assertInstanceOf(EmployeeRow::CLASS, $actual[1]);
        $this->assertInstanceOf(EmployeeRow::CLASS, $actual[2]);
        $this->assertSame($expect[0], $actual[0]->getArrayCopy());
        $this->assertSame($expect[1], $actual[1]->getArrayCopy());
        $this->assertSame($expect[2], $actual[2]->getArrayCopy());

        // fetch failure
        $actual = $this->table->fetchRows([997, 998, 999]);
        $this->assertSame([], $actual);
    }

    public function testFetchRows_compositeKey()
    {
        $table = $this->tableLocator->get(CourseTable::CLASS);

        $expect = [
            [
                'course_subject' => 'MATH',
                'course_number' => '100',
                'title' => 'Algebra',
            ],
            [
                'course_subject' => 'ENGL',
                'course_number' => '100',
                'title' => 'Composition',
            ],
            [
                'course_subject' => 'HIST',
                'course_number' => '100',
                'title' => 'World History',
            ],
        ];

        $actual = $table->fetchRows($expect);
        $this->assertCount(3, $actual);
        $this->assertInstanceOf(CourseRow::CLASS, $actual[0]);
        $this->assertInstanceOf(CourseRow::CLASS, $actual[1]);
        $this->assertInstanceOf(CourseRow::CLASS, $actual[2]);
        $this->assertSame($expect[0], $actual[0]->getArrayCopy());
        $this->assertSame($expect[1], $actual[1]->getArrayCopy());
        $this->assertSame($expect[2], $actual[2]->getArrayCopy());
    }

    public function testInsertRow()
    {
        $row = $this->table->newRow([
            'id' => null,
            'name' => 'Mona',
            'building' => '10',
            'floor' => '99',
        ]);

        // does the insert *look* successful?
        $success = $this->table->insertRow($row);
        $this->assertTrue($success);

        // did the autoincrement ID get retained?
        $this->assertEquals(13, $row->id);

        // was it *actually* inserted?
        $expect = [
            'id' => '13',
            'name' => 'Mona',
            'building' => '10',
            'floor' => '99',
        ];
        $actual = $this->table->getReadConnection()->fetchOne(
            'SELECT * FROM employee WHERE id = 13'
        );
        $this->assertSame($expect, $actual);

        // try to insert again, should fail on unique name
        $this->silenceErrors();
        $this->expectException(
            Exception::CLASS,
            "Expected 1 row affected, actual 0"
        );
        $this->table->insertRow($row);
    }

    public function testUpdateRow()
    {
        // fetch a record, then modify and update it
        $row = $this->table->fetchRow(1);
        $row->name = 'Annabelle';

        // did the update *look* successful?
        $success = $this->table->updateRow($row);
        $this->assertTrue($success);

        // was it *actually* updated?
        $expect = $row->getArrayCopy();
        $actual = $this->table->getReadConnection()->fetchOne(
            "SELECT * FROM employee WHERE id = 1"
        );
        $this->assertSame($expect, $actual);

        // try to update again, should be a no-op because there are no changes
        $this->assertFalse($this->table->updateRow($row));

        // delete the record ...
        $this->assertTrue($this->table->deleteRow($row));

        // ... then try to update it, should fail.
        $row->name = 'Foo';
        $this->expectException(
            Exception::CLASS,
            "Expected 1 row affected, actual 0"
        );
        $this->table->updateRow($row);
    }

    public function testDeleteRow()
    {
        // fetch a record, then delete it
        $row = $this->table->fetchRow(1);
        $this->table->deleteRow($row);

        // did it delete?
        $actual = $this->table->fetchRow(1);
        $this->assertNull($actual);

        // do we still have everything else?
        $actual = $this->table->select()->columns('COUNT(*)')->fetchValue();
        $expect = 11;
        $this->assertEquals($expect, $actual);

        // try to delete the record again
        $this->expectException(
            Exception::CLASS,
            "Expected 1 row affected, actual 0"
        );
        $this->table->deleteRow($row);
    }

    public function testSelect_whereEquals()
    {
        $actual = $this->table->select(['id' => [1, 2, 3]])->fetchRows();
        $this->assertCount(3, $actual);
    }

    protected function silenceErrors()
    {
        $conn = $this->table->getWriteConnection();
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
    }
}