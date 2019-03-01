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
    // 最终生成的配置
    public $curl_opt_array = [];
    // 最大批处理个数
    public $max_thread = 500;
    // 最大重试次数
    public $max_try = 3;
    // 待处理 url 池
    private $urls_pool = [];
    // 批处理句柄
    public $mh = NULL;
    // 用来存储运行中的信息
    public $running_info = [];
    // 运行信息初始值，用来初始化 $this->running_info
    private $running_info_default = [
        'urls_count'    => 0,     // 传入 multi 方法的 url 数量
        'ch_done_count' => 0,     // 已处理句柄数量
        'block_count'   => 0,     // 阻塞次数
        'retry_info'    => [],    // 记录重试信息，键名为 url，键值为已重试的次数
        'size_download' => 0,     // 记录累计下载数据量，以字节为单位
        'time_start'    => 0.0    // 记录批处理开始时间
    ];
    // 存储 CURLE_* 错误常量数组索引，用来解释错误代码
    public $curle_constants = [];


    /**
     * SimpleCurl constructor. 检查 cUrl 版本是否符合要求
     * @throws ErrorException
     */
    function __construct()
    {
        $version = explode('.',curl_version()['version'],3);
        array_walk($version,function (&$value) {
            $value = (int) $value;
        });
        $toggle = false;
        if ($version[0] > 7) {
            $toggle = true;
        } elseif ($version[0] = 7 && $version[1] > 10) {
            $toggle = true;
        } elseif ($version[0] = 7 && $version[1] == 10 && $version[2] >=3) {
            $toggle = true;
        }
        if (!$toggle) {
            throw new ErrorException('需要 cUrl 版本大于等于 7.10.3，您的 cUrl 的版本为：'.curl_version()['version']);
        }
    }


    /**
     * 生成最终的配置选项
     *
     * @param array $opt
     */
    public function optArr(array $opt)
    {
        $this->curl_opt_array = $opt + $this->curl_opt_default;
    }


    /**
     * 为一个 cUrl 句柄设置选项
     *
     * @param $ch resource 一个 cUrl 句柄
     * @throws ErrorException
     */
    private function setOpt($ch)
    {
        if (empty($this->curl_opt_array)) {
            $this->curl_opt_array = $this->curl_opt_default;
        }
        $result = curl_setopt_array($ch, $this->curl_opt_array);
        if (!$result) {
            throw new ErrorException('CURL 选项设置失败');
        }
//        if (!empty($this->cookie_array)) {
//          foreach ($this->cookie_array as $cookie_line) {
//              curl_setopt($ch, CURLOPT_COOKIELIST, $cookie_line);
//          }
//        }
    }


    public function get($url)
    {
        $ch = curl_init($url);
        $this->setOpt($ch);
        $content = curl_exec($ch);
        // print_r(curl_getinfo($ch, CURLINFO_COOKIELIST));
        $this->cookie_array = curl_getinfo($ch, CURLINFO_COOKIELIST);
        curl_close($ch);
        return $content;
    }

    public function post($url, array $post_data)
    {
        $ch = curl_init($url);
        $this->setOpt($ch);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        $content = curl_exec($ch);
        // print_r(curl_getinfo($ch, CURLINFO_COOKIELIST));
        $this->cookie_array = curl_getinfo($ch, CURLINFO_COOKIELIST);
        curl_close($ch);
        return $content;
    }


    /**
     * 为一个将被添加进批处理的 cUrl 句柄设置选项。
     * 主要是目的是将 url 写入句柄的 CURLOPT_PRIVATE 选项，以便后期进行识别。
     * CURLOPT_PRIVATE 选项自 cURL 7.10.3 版本才被支持。
     *
     * @param $ch   resource
     * @param $url  string
     * @throws ErrorException
     */
    private function setOptForMulti($ch, $url)
    {
        $this->setOpt($ch);
        $result = curl_setopt($ch, CURLOPT_PRIVATE, $url);
        if (!$result) {
            throw new ErrorException('CURL 选项设置失败');
        }
    }

    /**
     * 输出运行信息
     */
    private function displayRunningInfo()
    {
        $system_usage = $this->getHumanSize(memory_get_usage(true));
        $real_usage = $this->getHumanSize(memory_get_usage());
        // 计算平均下载速度
        $time_used = microtime(true) - $this->running_info['time_start'];
        $size_download = $this->running_info['size_download'];
        if ($size_download) {
            $speed = (int) $size_download/$time_used;
            $speed = $this->getHumanSize($speed).'/s';
        } else {
            $speed = '0.0 b/s';
        }
        $format = "\r句柄:%9d, 分配内存:%10s, 使用内存:%10s, 平均速度:%12s";
        $data = [
            $format,
            $this->running_info['ch_done_count'],
            $system_usage,
            $real_usage, 
            $speed,
        ];
        if ($block_count = $this->running_info['block_count']) {
            $data[0] .=  ", 阻塞%6d次";
            $data[] = $block_count;
        }
        if ($retry_count = count($this->running_info['retry_info'])) {
            $data[0] .=  ", 错误%6d个";
            $data[] = $retry_count;
        }
        call_user_func_array('printf',$data);
    }

    /**
     * 批处理 cUrl 连接 ，
     *
     * @param array $urls 包含需要处理的 url 的数组
     * @param callable $callable_on_success 处理成功时执行的回调函数，第一个参数为 url，第二个参数为返回的内容
     * @param callable|NULL $callable_on_fail 处理失败时执行的回调涵涵，第一个参数为 url，第二个参数为错误信息
     * @throws ErrorException
     */
    public function multi(array $urls, callable $callable_on_success, callable $callable_on_fail = NULL)
	{
	    // 初始化运行信息数组
	    $this->running_info = $this->running_info_default;

	    $this->running_info['urls_count'] = count($urls);
        if ($this->running_info['urls_count'] == 0) {
            echo '没有网址传入！', PHP_EOL;
            return;
        }

        // 耗时记时开始
        $t1 = $this->running_info['time_start'] = microtime(true);

        $this->urls_pool = $urls;
        unset($urls);

		$this->mh = curl_multi_init();
//		curl_multi_setopt($this->mh, CURLMOPT_MAXCONNECTS, $this->max_thread);
//		curl_multi_setopt($this->mh, CURLMOPT_PIPELINING, 1);

		// 根据 $this->max_thread 控制加入批处理的句柄数量
		for ($i = 1; $i <= $this->max_thread; $i++) {
		    $this->curlMultiAddHandle();
        }

        // 下面执行两层循环的意义在于，可以在批处理过程中根据需要进行 curl_multi_add_handle 等操作
		$active = null;
		do {
			$mrc = curl_multi_exec($this->mh, $active);
			// echo '进入第一层循环', PHP_EOL, '$mrc 的值为：', $mrc, PHP_EOL, '$active 的值为：', $active, PHP_EOL;
		} while ($mrc == CURLM_CALL_MULTI_PERFORM);
		while ($active && $mrc == CURLM_OK) {
		    // 检查是否出现阻塞
			if (curl_multi_select($this->mh, 120) == -1) {
			    $this->running_info['block_count']++;
				sleep(1);
			}

			do {
				$mrc = curl_multi_exec($this->mh, $active);
			} while ($mrc == CURLM_CALL_MULTI_PERFORM);
			// 当检测到批处理中有某个 cUrl 句柄处理完成时，对其进行操作
			while ($done = curl_multi_info_read($this->mh)) {
			    // 获取处理完成的句柄
				$done_ch = $done['handle'];
				// 更新运行信息
				$this->running_info['ch_done_count']++;
				if ($size_download = curl_getinfo($done_ch, CURLINFO_SIZE_DOWNLOAD)) {
				    $this->running_info['size_download'] += $size_download;
                }
				// 显示运行信息
				$this->displayRunningInfo();
				// 判断结果是否正常
				if ($done['result'] !== CURLE_OK || (int)curl_getinfo($done_ch, CURLINFO_HTTP_CODE) >= 400) {
				    // 如果结果不正常（传输异常 或者 传输正常但 HTTP 状态码大于等400）
					$this->onFail($done_ch, $done['result'], $callable_on_fail);
				} else {
				    // 如果结果正常 执行回调函数，添加新句柄到批处理句柄中
					$content = curl_multi_getcontent($done_ch);
					$url = curl_getinfo($done_ch, CURLINFO_PRIVATE);
					call_user_func($callable_on_success, $url, $content);
					$this->curlMultiRemoveHandle($done_ch);
					$this->curlMultiAddHandle();
				}
			}
		}
		curl_multi_close($this->mh);
		unset($this->ch_pool);
		$t2 = microtime(true);
        echo PHP_EOL, '共耗时：', $this->getHumanTime((int) round($t2-$t1,0)), "。";
        echo "平均每个任务耗时：", floor(((int)(round($t2-$t1,3) * 1000))/$this->running_info['urls_count']), 'ms', PHP_EOL;
	}


    /**
     * 对出现错误信息的 cUrl 句柄进行处理
     *
     * @param $ch              resource
     * @param null $ch_result string
     * @param callable $callable_on_fail callable
     * @throws ErrorException
     */
    private function onFail($ch, $ch_result = NULL, callable $callable_on_fail = NULL)
	{
		$url = curl_getinfo($ch, CURLINFO_PRIVATE);
		if (isset($this->running_info['retry_info'][$url])) {
		    $this->running_info['retry_info'][$url]++;
        } else {
		    $this->running_info['retry_info'][$url] = 1;
        }
		if ($this->max_try > 0 && $this->running_info['retry_info'][$url] <= $this->max_try) {
		    // 符合重试条件：记录重试次数并再次添加批处理句柄中
			$this->curlMultiRemoveHandle($ch);
			curl_multi_add_handle($this->mh, $this->curlInitForMulti($url));
		} else {
		    // 不符合重试条件：抛出错误信息或执行回调函数，添加新句柄到批处理句柄中
			$curl_errno = curl_errno($ch);
			$curl_error = curl_error($ch);
			$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$this->getCURLEConstants();
			$fail_message = '';
			if ($curl_errno) {
			    $fail_message .= "最后一次 Curl 错误代码：".$this->curle_constants[$curl_errno]."(${curl_errno}). ";;
            }
			if ($curl_error) {
			    $fail_message .= "最后一次 Curl 错误信息：${curl_error}. ";
            }
			if ($ch_result) {
			    $fail_message .= "批处理消息：".$this->curle_constants[$ch_result]."(${ch_result}). ";
            }
			if ($http_code) {
			    $fail_message .= "HTTP 状态码为 $http_code";
            }
			if (is_null($callable_on_fail)) {
			    user_error($fail_message."地址为：$url");
            } else {
			    call_user_func($callable_on_fail, $url, $fail_message);
            }
			$this->curlMultiRemoveHandle($ch);
			$this->curlMultiAddHandle();
		}
	}

    /**
     *  生成新 cUrl 句柄并添加到批处理中
     */
    private function curlMultiAddHandle()
    {
        if (!empty($this->urls_pool)) {
            $url = array_shift($this->urls_pool);
            $result = curl_multi_add_handle($this->mh, $this->curlInitForMulti($url));
            if ($result !== 0) {
                throw new ErrorException('批处理添加句柄失败，返回代码：'.$result);
            }
        }
	}


    /**
     * 为批处理初始化的句柄
     *
     * @param $url  string
     * @return false|resource
     * @throws ErrorException
     */
    private function curlInitForMulti($url)
    {
        $ch = curl_init($url);
        $this->setOptForMulti($ch, $url);
        return $ch;
	}


    /**
     * 将句柄从批处理中移除，并关闭该句柄
     *
     * @param $ch  resource
     * @throws ErrorException
     */
    private function curlMultiRemoveHandle($ch)
    {
        $result = curl_multi_remove_handle($this->mh, $ch);
        if ($result !== 0) {
            throw new ErrorException('批处理移除句柄失败，返回代码：'.$result);
        }
        curl_close($ch);
	}


    /**
     * 获取 CURLE_* 错误常量数组索引，用来解释错误代码
     */
    public function getCURLEConstants()
    {
        if (!empty($this->curle_constants)) {
            return;
        }
        $curl_constants = get_defined_constants(true)['curl'];
        $curle_constants = [];
        array_walk($curl_constants, function ($value,$key) use(&$curle_constants) {
            if (strpos($key,'CURLE_') === 0) {
                $curle_constants[$value] = $key;
            }
        });
        ksort($curle_constants);
        $this->curle_constants = $curle_constants;
	}


    /**
     * 将秒数格式化
     *
     * @param $sec  integer 秒数
     * @return string       格式化后的时间
     */
    public function getHumanTime($sec)
	{
	    if (!is_int($sec)) {
	        user_error('传入的参数为 非整数，'.$sec);
	        exit;
        }
		$result = '00 时 00 分 00 秒';
		if ($sec>0) {
			$hour = floor($sec/3600);
			$minute = floor(($sec-3600 * $hour)/60);
			$second = ($sec-3600 * $hour) - 60 * $minute;
			$result = $hour.' 时 '.$minute.' 分 '.$second.' 秒';
		}
		return $result;
	}


    /**
     * 换算 字节 为更合适的单位
     * 具体内容来自 http://php.net/manual/zh/function.memory-get-usage.php 页面上第一个笔记
     *
     * @param $size   integer
     * @return string
     */
    public function getHumanSize($size)
    {
        $unit=array('b','kb','mb','gb','tb','pb');
        return @round($size/pow(1024,($i=floor(log($size,1024)))),2).' '.$unit[$i];
    }

	public function getCookieArray($content)
	{
		$is_success = preg_match_all('@(?<=Set-Cookie:\s).*?(?=;)@', $content, $matches);
		if ($is_success === FALSE) {
			exit(PHP_EOL.'提取 cookie 数组失败!'.PHP_EOL.$content);
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