<?php

class MultiProgress {

	protected $sPipePath;
	protected $processIndex = -1;
	protected $pid = 0;

	const TIME_OUT = 30;

	public function __construct() {
		$this->sPipePath = "my_pipe." . posix_getpid();
		posix_mkfifo($this->sPipePath, 0666);
	}

	public function routine(callable $callback) {
		if ($this->processIndex < 0) {
			$this->processIndex = 0;
		}

		$index = $this->processIndex;
		$this->processIndex++;
		$this->pid = pcntl_fork(); // 创建子进程
		if ($this->pid === 0) {
			// 子进程
			$res = $callback();

			$oW = fopen($this->sPipePath, 'w');
			$funcResult = json_encode([
				'index' => $index,
				'result' => serialize($res),
			]);

			fwrite($oW, "{$funcResult}\n"); // 当前任务处理完比，在管道中写入数据
			fclose($oW);
			exit(0);
		}
	}

	public function channel() {
		if ($this->processIndex < 0) {
			return [];
		}

		// 父进程
		$oR = fopen($this->sPipePath, 'r');
		stream_set_blocking($oR, FALSE); // 将管道设置为非堵塞，用于适应超时机制
		$res = []; // 存放管道中的数据
		$lineCount = 0;
		$nStart = time();
		$doneIndexes = [];

		while ($lineCount < $this->processIndex && (time() - $nStart) < static::TIME_OUT) {
			$stringLine = fread($oR, 10240000);
			if (empty($stringLine)) {
				continue;
			}

			$resArray = array_map(function($item) use (&$doneIndexes) {
				$obj = json_decode($item, true);
				array_push($doneIndexes, $obj['index']);
				return $obj;
			}, array_filter(explode("\n", $stringLine)));

			$res = array_merge($res, $resArray);
			$lineCount += count($resArray);
		}

		for ($i = 0; $i < $this->sPipePath; $i++) {
			if (!in_array($i, $doneIndexes)) {
				array_push($res, [
					'index' => $i,
					'result' => null,
				]);
			}
		}

		usort($res, function($item1, $item2) {
			return $item1['index'] <=> $item2['index'];
		});

		fclose($oR);
		// 等待子进程执行完毕，避免僵尸进程
		$n = 0;
		while ($n < $this->processIndex) {
			$nStatus = -1;
			$nPID = pcntl_wait($nStatus, WNOHANG);
			if ($nPID > 0) {
				++$n;
			}
		}

		$this->processIndex = 0;

		return array_map(function($item) {
			return unserialize($item['result']);
		}, $res);
	}

	public function __destruct() {
		if ($this->pid !== 0) {
			// 彻底删除管道，已经没有作用了
			unlink($this->sPipePath);
		}
	}
}
