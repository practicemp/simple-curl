<?php
namespace PracticeMP\Curl;
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
    // 在进行批处理时是否打印运行信息
    public $display_running_info = true;
    // 最终生成的配置
    public $curl_opt_array = [];
    // 最大批处理个数
    public $max_thread = 100;
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
     * @throws \ErrorException
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
            throw new \ErrorException('需要 cUrl 版本大于等于 7.10.3，您的 cUrl 的版本为：'.curl_version()['version']);
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
     * @throws \ErrorException
     */
    private function setOpt($ch)
    {
        if (empty($this->curl_opt_array)) {
            $this->curl_opt_array = $this->curl_opt_default;
        }
        $result = curl_setopt_array($ch, $this->curl_opt_array);
        if (!$result) {
            throw new \ErrorException('CURL 选项设置失败');
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
     * @throws \ErrorException
     */
    private function setOptForMulti($ch, $url)
    {
        $this->setOpt($ch);
        $result = curl_setopt($ch, CURLOPT_PRIVATE, $url);
        if (!$result) {
            throw new \ErrorException('CURL 选项设置失败');
        }
    }


    /**
     * 批处理 cUrl 连接 ，
     *
     * @param array $urls 包含需要处理的 url 的数组
     * @param callable $callable_on_success 处理成功时执行的回调函数，第一个参数为 url，第二个参数为返回的内容
     * @param callable|NULL $callable_on_fail 处理失败时执行的回调涵涵，第一个参数为 url，第二个参数为与之对应错误信息
     * @throws \ErrorException
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

        // 初始化批处理句柄
		$this->mh = curl_multi_init();
//		curl_multi_setopt($this->mh, CURLMOPT_MAXCONNECTS, $this->max_thread);
//		curl_multi_setopt($this->mh, CURLMOPT_PIPELINING, 1);

		// 根据 $this->max_thread 和 $this->urls_pool 控制第一批加入批处理的句柄数量
        $i = 1;
        do {
            $result = $this->curlMultiAddHandle();
            $i++;
        } while ($i <= $this->max_thread && $result);

        // 这个 do while 循环用来启动后面的 while 循环
		$active = null;
		do {
			$mrc = curl_multi_exec($this->mh, $active);
		} while ($mrc == CURLM_CALL_MULTI_PERFORM);
        // 这个 while 循环用来保证「中途」被添加进批处理的句柄可以被处理
		while ($active && $mrc == CURLM_OK) {
		    // 检查是否出现阻塞
			if (curl_multi_select($this->mh, 120) == -1) {
			    $this->running_info['block_count']++;
				sleep(1);
			}
            // 处理处理在栈中的每一个句柄
			do {
				$mrc = curl_multi_exec($this->mh, $active);
			} while ($mrc == CURLM_CALL_MULTI_PERFORM);
			// 当检测到批处理中有某个 cUrl 句柄处理完成时，从批处理中移除，并添加新的句柄到批处理
			while ($done = curl_multi_info_read($this->mh)) {
			    // 获取处理完成的句柄
				$done_ch = $done['handle'];
				// 更新运行信息
				$this->running_info['ch_done_count']++;
				if ($size_download = curl_getinfo($done_ch, CURLINFO_SIZE_DOWNLOAD)) {
				    $this->running_info['size_download'] += $size_download;
                }
				// 显示运行信息
                if ($this->display_running_info) {
                    $this->displayRunningInfo();
                }
				// 判断结果是否正常
				if ($done['result'] !== CURLE_OK || (int)curl_getinfo($done_ch, CURLINFO_HTTP_CODE) >= 400) {
				    // 如果结果不正常（传输异常或者传输正常但 HTTP 状态码大于等 400）
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
		// 打印运行信息所需的输出控制缓存在这里关闭
        if ($this->display_running_info) {
            ob_end_flush();
        }
		curl_multi_close($this->mh);
		unset($this->ch_pool);
		$t2 = microtime(true);
        echo PHP_EOL, '共耗时：', $this->getHumanTime((int) round($t2-$t1,0)), "。";
        echo "平均每个任务耗时：", floor(((int)(round($t2-$t1,3) * 1000))/$this->running_info['urls_count']), 'ms', PHP_EOL;
	}


    /**
     * 对出现错误信息的 cUrl 句柄进行处理，并更新批处理中的句柄
     * 如果没有传入处理错误信息的回调函数，错误信息会被打印出来，反之不会。
     * 处理错误信息的回调函数会被传入 2 个参数，第一个是 url ，第二个是与之对应的错误信息
     *
     * @param $ch              resource
     * @param null $ch_result string
     * @param callable $callable_on_fail callable
     * @throws \ErrorException
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
                if (isset($this->curle_constants[$curl_errno])) {
                    $fail_message .= "最后一次 Curl 错误代码：".$this->curle_constants[$curl_errno]."(${curl_errno}). ";;
                } else {
                    $fail_message .= "最后一次 Curl 错误代码：${curl_errno}. ";;
                }			    
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
     *  添加一个 cUrl 句柄到批处理中
     */
    private function curlMultiAddHandle()
    {
        if (!empty($this->urls_pool)) {
            $url = array_shift($this->urls_pool);
            $result = curl_multi_add_handle($this->mh, $this->curlInitForMulti($url));
            if ($result !== 0) {
                throw new \ErrorException('批处理添加句柄失败，返回代码：'.$result);
            }
            return true;
        }
        return false;
	}


    /**
     * 为批处理初始化的一个 cUrl 句柄
     *
     * @param $url  string
     * @return false|resource
     * @throws \ErrorException
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
     * @throws \ErrorException
     */
    private function curlMultiRemoveHandle($ch)
    {
        $result = curl_multi_remove_handle($this->mh, $ch);
        if ($result !== 0) {
            throw new \ErrorException('批处理移除句柄失败，返回代码：'.$result);
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
     * 输出运行信息
     */
    private function displayRunningInfo()
    {
        // 在第一次打印运行信息后才会开启输出控制缓冲
        if ($this->running_info['ch_done_count'] > 1) {
           ob_end_flush();
        }
        // 获取系统分配内存
        $system_usage = $this->getHumanSize(memory_get_usage(true));
        // 获取实际使用内存
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
        // 计算累计下载量
        $size_download = $this->getHumanSize($size_download);
        // 设置默认内容
        $info_arr = [
            '已处理句柄数' => $this->running_info['ch_done_count'],
            '分配内存量'    => $system_usage,
            '使用内存量'    => $real_usage,
            '平均速度'      => $speed,
            '累计下载量'    => $size_download,
            '重试的 Url 数' => count($this->running_info['retry_info']),
            '阻塞次数'      => $this->running_info['block_count'],
        ];
        // 剔除无用内容
        foreach ($info_arr as $key => $value) {
            if (!$value) {
                unset($info_arr[$key]);
            }
        }
        // 将标题和内容的宽度变成一样宽
        $info_arr_new = [];
        foreach ($info_arr as $name => $value) {
            $value = (string) $value;
            // 由于标题中含有中文，所以不能用 strlen 来判断宽度，strlen 会把一个汉字当做 3 个字符长度
            // 也不能用 mb_strlen，mb_strlen 会把汉字当做 1 个字符长度，只能用 mb_strwidth 这个函数
            $width_caption = mb_strwidth($name,'utf-8');
            $length_content = strlen($value);
            // 比较宽度来判断以哪个长度为标准
            // 在用 sprintf 格式化标题行时，要用回 strlen 来生成标题行的字符长度，以便正确显示。
            if ($width_caption > $length_content) {
                // 如果标题比内容宽
                $value = sprintf("%".$width_caption."s", $value);
            } else {
                // 如果内容比标题宽
                $length_caption = ($length_content - $width_caption) + strlen($name);
                $name = sprintf("%".$length_caption."s", $name);
            }
            $info_arr_new[$name] = $value;
        }
        // 以分栏符连接各个元素
        $caption = implode(" | ", array_keys($info_arr_new));
        $content = implode(" | ", array_values($info_arr_new));
        // 依靠终端控制符来实现同时刷新两行内容
        // 思路就是 光标上移\33[A->光标移至行首\r->清除光标后的内容\33[K->打印标题行->换行
        // ->清除光标后的内容\33[K->打印内容行
        $info = "\33[A\r\33[K".$caption.PHP_EOL."\33[K".$content;
        // 如果是第一次出现，需要在开头添加一个换行，否则光标上移会清除上面一行
        if ($this->running_info['ch_done_count'] === 1) {
           $info = PHP_EOL.$info;
        }
        // 打印运行信息
        echo $info;
        // 检测缓冲区内容的开头和结尾是否有换行符，没有的话添加一个
        ob_start( function ($content) {
            if ($content) {
                // 检查开头是否有换行
                if (strpos($content,PHP_EOL) !== 0) {
                    $content = PHP_EOL.$content;
                }
                // 检查结尾是否有换行
                if (strlen($content) !== strpos($content,PHP_EOL,-1) + 1) {
                    $content .= PHP_EOL;
                }
                return $content;
            }
        });
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
		$result = '00时 00分 00秒';
		if ($sec>0) {
			$hour = (int) floor($sec/3600);
			$minute = (int) floor(($sec-3600 * $hour)/60);
			$second = ($sec-3600 * $hour) - 60 * $minute;

			$hour = sprintf('%2d', $hour);
            $minute = sprintf('%2d', $minute);
            $second = sprintf('%2d', $second);

			$result = $hour.'时 '.$minute.'分 '.$second.'秒';
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
        return sprintf("%0.2f", @round($size/pow(1024,($i=floor(log($size,1024)))),2)).' '.$unit[$i];
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