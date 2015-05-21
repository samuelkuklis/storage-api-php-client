<?php
/**
 * Created by PhpStorm.
 * User: martinhalamicek
 * Date: 21/05/15
 * Time: 13:19
 */

use Keboola\StorageApi\Client,
	Keboola\Csv\CsvFile;

class Keboola_StorageApi_Tables_MetricsTest extends StorageApiTestCase
{


	public function setUp()
	{
		parent::setUp();
		$this->_initEmptyBucketsForAllBackends();
	}

	/**
	 * @dataProvider importMetricsData
	 * @param $backend
	 * @param CsvFile $csvFile
	 * @param $expectedMetrics
	 */
	public function testTableCreateMetrics($backend, CsvFile $csvFile, $expectedMetrics)
	{
		$fileId = $this->_client->uploadFile(
			$csvFile->getPathname(),
			(new \Keboola\StorageApi\Options\FileUploadOptions())
				->setNotify(false)
				->setIsPublic(false)
				->setCompress(false)
				->setTags(array('table-import'))
		);

		$bucketId = $this->getTestBucketId(self::STAGE_IN, $backend);
		$job = $this->_client->apiPost("storage/buckets/{$bucketId}/tables-async", [
			'name' => 'languages',
			'dataFileId' => $fileId,
		], false);

		$job = $this->_client->waitForJob($job['id']);

		$this->assertArrayHasKey('metrics', $job);
		$this->assertEquals($expectedMetrics, $job['metrics']);
	}

	/**
	 * @dataProvider importMetricsData
	 * @param $backend
	 */
	public function testAsyncImportMetrics($backend, CsvFile $csvFile, $expectedMetrics)
	{
		$tableId = $this->_client->createTable($this->getTestBucketId(self::STAGE_IN, $backend), 'languages', $csvFile);

		$fileId = $this->_client->uploadFile(
			$csvFile->getPathname(),
			(new \Keboola\StorageApi\Options\FileUploadOptions())
				->setNotify(false)
				->setIsPublic(false)
				->setCompress(false)
				->setTags(array('table-import'))
		);
		$job = $this->_client->apiPost("storage/tables/{$tableId}/import-async", [
			'dataFileId' => $fileId
		], false);
		$job = $this->_client->waitForJob($job['id']);

		$this->assertArrayHasKey('metrics', $job);
		$this->assertEquals($expectedMetrics, $job['metrics']);
	}

	/**
	 * @dataProvider backends
	 * @param $backend
	 */
	public function testTableExportMetrics($backend)
	{
		$csvFile =  new CsvFile(__DIR__ . '/../_data/languages.csv');
		$tableId = $this->_client->createTable($this->getTestBucketId(self::STAGE_IN, $backend), 'languages', $csvFile);

		$job = $this->_client->apiPost("storage/tables/{$tableId}/export-async", [], false);
		$job = $this->_client->waitForJob($job['id']);

		$metrics = $job['metrics'];
		$this->assertEquals(0, $metrics['inBytes']);
		$this->assertEquals(0, $metrics['inBytesUncompressed']);
		$this->assertFalse($metrics['inCompressed']);

		$this->assertFalse($metrics['outCompressed']);
		$this->assertGreaterThan(0, $metrics['outBytes']);
		$this->assertGreaterThan(0, $metrics['outBytesUncompressed']);
		$this->assertEquals($metrics['outBytes'], $metrics['outBytesUncompressed']);

		$previousMetrics = $metrics;

		// compress
		$job = $this->_client->apiPost("storage/tables/{$tableId}/export-async", ['gzip' => true], false);
		$job = $this->_client->waitForJob($job['id']);

		$metrics = $job['metrics'];
		$this->assertEquals(0, $metrics['inBytes']);
		$this->assertEquals(0, $metrics['inBytesUncompressed']);
		$this->assertFalse($metrics['inCompressed']);

		$this->assertTrue($metrics['outCompressed']);
		$this->assertGreaterThan(0, $metrics['outBytes']);
		$this->assertEmpty($metrics['outBytesUncompressed']);
		$this->assertLessThan($metrics['outBytes'], $previousMetrics['outBytes']);
	}

	public function importMetricsData()
	{
		$csvFile =  new CsvFile(__DIR__ . '/../_data/languages.csv');
		$csvFileSize = filesize($csvFile);

		$csvFileGz = new CsvFile(__DIR__ . '/../_data/languages.csv.gz');
		$csvFileGzSize = filesize($csvFileGz);

		return [
			[self::BACKEND_MYSQL, $csvFile, [
				'inCompressed' => false,
				'inBytes' => $csvFileSize,
				'inBytesUncompressed' => $csvFileSize,
				'outCompressed' => false,
				'outBytes' => 0,
				'outBytesUncompressed' => 0,
			]],
			[self::BACKEND_REDSHIFT, $csvFile, [
				'inCompressed' => false,
				'inBytes' => $csvFileSize,
				'inBytesUncompressed' => $csvFileSize,
				'outCompressed' => false,
				'outBytes' => 0,
				'outBytesUncompressed' => 0,
			]],
			[self::BACKEND_MYSQL, $csvFileGz, [
				'inCompressed' => true,
				'inBytes' => $csvFileGzSize,
				'inBytesUncompressed' => $csvFileSize,
				'outCompressed' => false,
				'outBytes' => 0,
				'outBytesUncompressed' => 0,
			]],
			[self::BACKEND_REDSHIFT, $csvFileGz, [
				'inCompressed' => true,
				'inBytes' => $csvFileGzSize,
				'inBytesUncompressed' => 0, // We don't know uncompressed size of file
				'outCompressed' => false,
				'outBytes' => 0,
				'outBytesUncompressed' => 0,
			]]
		];
	}

}