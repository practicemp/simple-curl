<?php

class SimpleCurl
{
	public $cookie_array = [];
	public $cookie_string = '';
	public $headers = [];
	public $curl_opt_default = [
		CURLOPT_RETURNTRANSFER => TRUE,
		CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_2) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/71.0.3578.98 Safari/537.36',
		CURLOPT_FOLLOWLOCATION => TRUE,
		CURLOPT_MAXREDIRS => 3,
		CURLOPT_CONNECTTIMEOUT => 60,
        CURLOPT_TIMEOUT => 120,
	];
	public $curl_opt_array = [];
	public $maxThread = 500;
	// 需要重试的句柄，键名为句柄，键值为已重试的次数
	private $retry_count = [];

	public $maxTry = 3;
	private $ch_pool = [];
	public $mh = NULL;


	function __construct () {
		echo 'curl 的版本为：', curl_version()['version'], PHP_EOL;
	}


	public function setopt(array $opt)
	{
		$this->curl_opt_array = $opt + $this->curl_opt_default;
	}


	public function get($url)
	{
		$ch = curl_init($url);
		$this->opt($ch);
		$content = curl_exec($ch);
		// print_r(curl_getinfo($ch, CURLINFO_COOKIELIST));
		$this->cookie_array = curl_getinfo($ch, CURLINFO_COOKIELIST);
		curl_close($ch);
		return $content;
	}

	public function post($url, array $post_data)
	{
		$ch = curl_init($url);
		$this->opt($ch);
		curl_setopt($ch, CURLOPT_POST, TRUE);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
		$content = curl_exec($ch);
		// print_r(curl_getinfo($ch, CURLINFO_COOKIELIST));
		$this->cookie_array = curl_getinfo($ch, CURLINFO_COOKIELIST);
		curl_close($ch);
		return $content;
	}

	private function opt($ch)
	{
		if (empty($this->curl_opt_array)) {
			$this->curl_opt_array = $this->curl_opt_default;
		}
		curl_setopt_array($ch, $this->curl_opt_array);
		if (!empty($this->cookie_array)) {
			foreach ($this->cookie_array as $cookie_line) {
				curl_setopt($ch, CURLOPT_COOKIELIST, $cookie_line);
			}
		}
		// curl_setopt($ch, CURLOPT_SHARE, $this->sh);
	}


	public function multi(array $urls, callable $callable)
	{
		$t1 = microtime(true);
		foreach ($urls as $url) {
			$ch = curl_init($url);
			$this->opt($ch);
			curl_setopt($ch, CURLOPT_PRIVATE, $url);
			$this->ch_pool[] = $ch;
		}
		$this->mh = curl_multi_init();
		curl_multi_setopt($this->mh, CURLMOPT_MAXCONNECTS, $this->maxThread);

		$count = 1;
		while (!empty($this->ch_pool) && $count <= $this->maxThread) {
			curl_multi_add_handle($this->mh, array_shift($this->ch_pool));
			$count++;
		}
		$active = null;
		$count = 1;
		$block_count = 1;
		do {
			$mrc = curl_multi_exec($this->mh, $active);
			// echo '进入第一层循环', PHP_EOL, '$mrc 的值为：', $mrc, PHP_EOL, '$active 的值为：', $active, PHP_EOL;
		} while ($mrc == CURLM_CALL_MULTI_PERFORM);
		while ($active && $mrc == CURLM_OK) {
			if (curl_multi_select($this->mh, 120) == -1) {
				printf("\r 阻塞失败: %d 次。", $block_count);
				$block_count++;
				sleep(1);
			} 
			do {
				$mrc = curl_multi_exec($this->mh, $active);
			} while ($mrc == CURLM_CALL_MULTI_PERFORM);
			while ($done = curl_multi_info_read($this->mh)) {
				$doneCh = $done['handle'];
				printf("\r 已处理的句柄数量为: %d", $count);
				$count++;
				if ($done['result'] !== CURLE_OK) {
					$this->onFail($doneCh, $done['result']);
				} elseif ((int)curl_getinfo($doneCh, CURLINFO_HTTP_CODE) >= 400) {
					$this->onFail($doneCh);
				} else {
					$content = curl_multi_getcontent($doneCh);
					$url = curl_getinfo($doneCh, CURLINFO_PRIVATE);
					call_user_func($callable, $url, $content);
					curl_multi_remove_handle($this->mh, $doneCh);
					curl_close($doneCh);
					if (!empty($this->ch_pool)) {
						curl_multi_add_handle($this->mh, array_shift($this->ch_pool));
					}
				}
			}
		}
		curl_multi_close($this->mh);
		$t2 = microtime(true);
		echo PHP_EOL, '共耗时：', $this->getHumanTime((int) round($t2-$t1,0)), "。平均每个任务耗时：", floor(((int)(round($t2-$t1,3) * 1000))/count($urls)), 'ms', PHP_EOL;
	}

	private function onFail($ch, $ch_result = NULL)
	{
		$url = curl_getinfo($ch, CURLINFO_PRIVATE);
		if ($this->maxTry>0 && $this->retry_count[$url] < $this->maxTry) {
			$this->retry_count[$url]++;
			curl_multi_remove_handle($this->mh, $ch);
			curl_close($ch);
			$ch_retry = curl_init($url);
			$this->opt($ch_retry);
			curl_setopt($ch_retry, CURLOPT_PRIVATE, $url);
			curl_multi_add_handle($this->mh, $ch_retry);
		} else {
			$curl_errno = curl_errno($ch);
			$curl_error = curl_error($ch);
			$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			user_error("Curl error($curl_errno -- $ch_result) $curl_error -- 返回的 http 状态码为 $code  --- 地址为 $url", E_USER_WARNING);
			curl_multi_remove_handle($this->mh, $ch);
			curl_close($ch);
			if (!empty($this->ch_pool)) {
				curl_multi_add_handle($this->mh, array_shift($this->ch_pool));
			}
		}
	}

	public function getHumanTime(int $sec)
	{
		$result = '00:00:00';
		if ($sec>0) {
			$hour = floor($sec/3600);
			$minute = floor(($sec-3600 * $hour)/60);
			$second = ($sec-3600 * $hour) - 60 * $minute;
			$result = $hour.':'.$minute.':'.$second;
		}
		return $result;
	}

	public function getCookieArray($content)
	{
		$is_success = preg_match_all('@(?<=Set-Cookie:\s).*?(?=;)@', $content, $matches);
		if ($is_success === FALSE) {
			exit('提取 cookie 数组失败!'.PHP_EOL.$content);
		}
		$cookie_array = [];
		foreach ($matches[0] as $value) {
			$item = explode('=', $value, 2);
			$cookie_array[$item[0]] = $item[1];
		}
		if (empty($this->cookie_array)) {
			$this->cookie_array = $cookie_array;
		} else {
			$this->cookie_array = array_merge($this->cookie_array, $cookie_array);
		}
	}

	public function getCookieString($content)
	{
		$this->getCookieArray($content);
		$cookie_array = [];
		foreach ($this->cookie_array as $key => $value) {
			$cookie_array[] = $key.'='.$value;
		}
		$cookie_string = implode('; ', $cookie_array);
		$this->cookie_string = $cookie_string;
	}

}