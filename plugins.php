<?php

function obt_times($index = null) {                         // 记录运行耗时（毫秒）
    static $times = array();
    if ($index !== null) {
        $times[$index] = 1000 * microtime(true);
    } else {                                                // 计算耗时
        $times[] = 1000 * microtime(true);
        $useup   = array();
        foreach ($times as $index => $time) {               // 计算各记录点的分段耗时
            $useup[$index + 1] = ($index === 0) ? $time : ($time - $times[$index - 1]);
        }
        $useup[0] = $useup[1];                              // 入口函数开始的绝对时间
        $useup[1] = end($times) - $useup[0];                // 总体耗时
        ksort($useup);
        // error_log(implode("\t", $useup) . "\n", 3, "/tmp/wap.log");
    }
}



function obt_vsnap($name = null, $vars = null) {            // 用于简单的调试内容输出
    static $vsnaps = array();                               // 因为模板被大量 ob_start 打乱了输出，用这个吧
    if ($name === null && $vars === null) {
        foreach ($vsnaps as $index => $item) {
            echo "\n", $item['name'], "=>", $item['vars'], "\n";
        }
    } else if ($vars === null) {
        $vars = $name;
        $name = microtime(true);
    }
    $vsnaps[] = array(
        'name' => $name,
        'vars' => print_r($vars, true),                     // 变量的快照
    );
}



function obt_plg_bcc(&$block = array(), $callback = null) { // 片段关闭时的回调，处理刚捕获到的片段内容
    $values = array();
    foreach ($block['value'] as $value) {
        if (is_array($value)) {
            $values[]   = $value;
        } elseif ($value) {
            $parts  = obt_plg_spl($value, $block['debug']);
            $values = array_merge($values, obt_plg_jon($parts, $block['debug']));
        }
    }
    $block['value'] = $values;
}



function obt_plg_cpl($tpath, $cpath) {                      // 模板预处理，并保存处理结果供后继直接使用
    @mkdir(dirname($cpath), 0777, true);
    $parts  = obt_plg_spl(file_get_contents($tpath));       // 找出并分解内容的主要成分
    $contnt = obt_plg_jon($parts, false, 'cpl');            // 按预处理规则重组各成分
    file_put_contents($cpath, implode('', $contnt));
}



function obt_plg_spl($contnt = '', $debug = false) {        // 用正则找出并分解内容的主要成分[html, style, script]
    $reg    = '/<(style|script) tob="(\w*)"(.*?)>([\s\S]*?)<\/(style|script)>/';
    $offset = 0;
    $matchs = array();                                      // 提取上面的 <style>, <script> 作特殊处理
    $parts  = array();                                      // 内容经过处理，被分解成多种成分，但保持原序
    if (!$debug) {                                          // 删除行首尾空白及多余的换行
        $contnt = preg_replace('/[\t ]*[\r\n]+[\t ]*/', "\n", trim($contnt));
    }
    if (!$contnt) {
        return $parts;
    }
    preg_match_all($reg, $contnt, $matchs, PREG_SET_ORDER + PREG_OFFSET_CAPTURE);
    foreach ($matchs as $match) {
        $parts[]    = array(                                // <script> 之前的内容
            'value' => substr($contnt, $offset, $match[0][1] - $offset),
        );
        $offset     = $match[5][1] + strlen($match[5][0]) + 1;
        $parts[]    = array(                                // <script>, <style> 内容
            'tag'   => $match[1][0],
            'type'  => $match[2][0],
            'attrs' => $match[3][0],
            'value' => $match[4][0],
        );
    }
    $parts[]    = array(                                    // </script> 之后的内容
        'value' => substr($contnt, $offset),
    );
    return $parts;
}



function obt_plg_jon($parts, $debug = false, $way = '') {  // 重组分解后的成分
    $contnt = array();
    foreach ($parts as $item) {
        if (!($item['tag'] === 'style' || $item['tag'] === 'script')) {
            $contnt[]   = $debug ? $item['value'] : preg_replace('/>\n+</', '><', $item['value']);
        } else if (!$item['type']) {                        // tob="" 的情况，只压缩，不作为特殊成分
            $contnt[]   = '' .
                '<' . $item['tag'] . '>' .
                    ($debug ? $item['value'] : obt_plg_zjc($item['value'], $item['tag'])) .
                '</' . $item['tag'] . '>' .
            '';
        } else if ($way === 'cpl') {
            $contnt[]   = '' .
                '<' . '?php obt_start("", array("type" => "' . $item['type'] . '"));?' . '>' .
                    ($debug ? $item['value'] : obt_plg_zjc($item['value'], $item['tag'])) .
                '<' . '?php obt_endc();?' . '>' .
            '';
        } else {
            $contnt[]   = array(
                'type'  => $item['type'],
                'value' => $debug ? $item['value'] : obt_plg_zjc($item['value'], $item['tag']),
            );
        }
    }
    return $contnt;
}



function obt_plg_zjc($content = '', $tag = '') {            // script, style 内容压缩，在前面已经做完空白压缩之后
    if ($tag === 'style') {
        $content = preg_replace('/\s*\/\*[\s\S]*?\*\/\s*/', '', $content);
        return preg_replace('/\n/', '', $content);          // 删除注释和换行
    }
    $lines      = explode("\n", $content);
    foreach ($lines as $index => $line) {                   // 逐行处理
        $line   = preg_replace('/\s*\/\/[^\'"]*$/', '', $line);
        if (strpos($line, '//') !== false || substr($line, -5) === '<' . '?php') { // 不要在 js 代码里各种使用 php
            $line .= "\n";                                  // 可疑的注释不能删除，其后的换行也不能删
        }
        $lines[$index] = $line;
    }
    return implode('', $lines);
}
