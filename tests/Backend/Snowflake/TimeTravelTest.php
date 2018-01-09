<?php

namespace Keboola\Test\Backend\Snowflake;

use Keboola\Csv\CsvFile;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\TableExporter;
use Keboola\Test\StorageApiTestCase;

class TimeTravelTest extends StorageApiTestCase
{
    private $downloadPath;

    /**
     * @var Client to project that has limit set for `storage.dataRetentionTimeInDays`
     */
    protected $timeTravelClient;

    public function setUp()
    {
        parent::setUp();
        $this->_initEmptyTestBuckets();
        $this->downloadPath = __DIR__ . '/../../_tmp/';
        $this->timeTravelClient = new Client([
            'token' => STORAGE_API_TIMETRAVEL_TOKEN,
            'url' => STORAGE_API_URL,
            'backoffMaxTries' => 1
        ]);
        $this->_initEmptyTestBuckets($this->timeTravelClient);
    }
    
    public function testCreateTableFromTimestamp()
    {
        $importFile = new CsvFile(__DIR__ . '/../../_data/languages.csv');

        $sourceTable = 'languages_' . date('Ymd_His');

        $sourceTableId = $this->timeTravelClient->createTable(
            $this->getTestBucketId(),
            $sourceTable,
            $importFile
        );
        $originalTable = $this->timeTravelClient->getTable($sourceTableId);
        // the timestamp must be at least 1 sec > creation time
        sleep(5);
        $timestamp = date(DATE_ATOM);
        sleep(25);

        $this->timeTravelClient->writeTable($sourceTableId, $importFile, ['incremental' => true]);

        $updatedTable = $this->timeTravelClient->getTable($sourceTableId);
        $newTableName = "new-table-name_" . date('Ymd_His', strtotime($timestamp));

        $replicaTableId = $this->timeTravelClient->createTableFromSourceTableAtTimestamp(
            $this->getTestBucketId(self::STAGE_OUT),
            $sourceTableId,
            $timestamp,
            $newTableName
        );

        $replicaTable = $this->timeTravelClient->getTable($replicaTableId);

        $this->assertEquals($newTableName, $replicaTable['name']);

        // Pending fix of timetravel metadata issue case 00022189
        //
        // $this->assertEquals($updatedTable['rowsCount'], $replicaTable['rowsCount'] * 2);
        // $this->assertEquals($originalTable['rowsCount'], $replicaTable['rowsCount']);

        // test data export
        $exporter = new TableExporter($this->timeTravelClient);
        $downloadFile = $this->downloadPath . 'timeTravelOutput.csv';
        $exporter->exportTable($replicaTableId, $downloadFile, []);
        $this->assertArrayEqualsSorted(
            Client::parseCsv(file_get_contents($importFile)),
            Client::parseCsv(file_get_contents($downloadFile)),
            'id'
        );
    }

    public function testCreateTableFromTimestampOfAlteredTable()
    {
        $importFile = new CsvFile(__DIR__ . '/../../_data/languages.csv');

        $sourceTable = 'languages_' . date('Ymd_His');

        $sourceTableId = $this->timeTravelClient->createTable(
            $this->getTestBucketId(),
            $sourceTable,
            $importFile
        );
        $originalTable = $this->timeTravelClient->getTable($sourceTableId);
        // the timestamp must be at least 1 sec > creation time
        sleep(5);
        $timestamp = date(DATE_ATOM);
        sleep(25);

        $this->_client->addTableColumn($sourceTableId, "new-column");

        $updatedTable = $this->timeTravelClient->getTable($sourceTableId);

        $newTableName = "new-table-name_" . date('Ymd_His', strtotime($timestamp));

        $replicaTableId = $this->timeTravelClient->createTableFromSourceTableAtTimestamp(
            $this->getTestBucketId(self::STAGE_OUT),
            $sourceTableId,
            $timestamp,
            $newTableName
        );

        $replicaTable = $this->timeTravelClient->getTable($replicaTableId);

        $this->assertEquals($newTableName, $replicaTable['name']);
        $this->assertEquals($originalTable['columns'], $replicaTable['columns']);
        $this->assertGreaterThan(count($replicaTable['columns']), count($updatedTable['columns']));
    }
}
