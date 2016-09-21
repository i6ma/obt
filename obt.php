<?php

include_once 'plugins.php';

global  $obt_conf;
global  $obt_dconf;
global  $obt_vars;

$obt_conf   = array();
$obt_dconf  = array(                                        // 配置项默认值
    'obt_ver'   => '0.3.3',
    'dir_bas'   => '',                                      // 基准目录，默认为此文件的上一级目录
    'dir_tpl'   => 'tpls',                                  // 模板源文件目录
    'dir_cpl'   => 'tplc',                                  // 模板预处理后目录
    'fun_cpl'   => 'obt_plg_cpl',                           // 模板预处理函数
    'fun_bcc'   => 'obt_plg_bcc',                           // 片段结束时的回调
    'debug'     => false,                                   // 调试模式时，不使用预处理，不压缩空白
);

$obt_vars   = array(
    'data'      => array(),                                 // 模板变量集
    'blocks'    => array(),                                 // block 片段集
    'bstack'    => array(),                                 // ob_start 嵌套信息栈
    'pstack'    => array(),                                 // 父模板懒函数栈
);

obt_conf();                                                 // 应用默认配置项



function obt_conf($conf = array()) {                        // 设定配置项
    global $obt_conf;
    global $obt_dconf;
    $conf       = array_merge($obt_dconf, $conf);
    $dir_sep    = DIRECTORY_SEPARATOR;
    $dir_bas    = $conf['dir_bas'] ?: __DIR__ . $dir_sep . '..';
    $dir_bas    = realpath($dir_bas) . $dir_sep;
    $conf['dir_bas']    = $dir_bas;                         // 基准目录
    $conf['dir_tpl']    = $dir_bas . $conf['dir_tpl'] . $dir_sep;
    $conf['dir_cpl']    = $dir_bas . $conf['dir_cpl'] . $dir_sep;
    $obt_conf   = array_merge($obt_conf, $conf);
}



function obt_view($obt_file, $obt_data) {                   // 入口函数
    global  $obt_vars;
    $obt_file   = obt_file($obt_file);                      // 拼装模板文件路径
    if (!$obt_file) {return false;}
    $obt_expt   = array();
    $obt_expt   = array_keys(get_defined_vars());           // 后面回收模板变量时，排除上面的临时(参数)变量
    extract(obt_data($obt_data), EXTR_SKIP + EXTR_REFS);    // 将模板数据展开成模板变量

    obt_block('');                                          // 捕获泄漏的片段
    include $obt_file;
    obt_block();

    obt_data(get_defined_vars(), $obt_expt);                // 回收模板变量，以供父模板懒函数使用
    obt_pfun();                                             // 依次执行各级父模板的懒函数
    obt_vsnap();                                            // 调试快照信息先于模板主体内容输出
    obt_flush();
}



function obt_file($file = '', $type = '') {                 // 拼装模板文件路径
    global  $obt_conf;
    if ($type === 'bas') {                                  // 普通文件添加基准目录
        $tpath  = realpath($obt_conf['dir_bas'] . $file);   // 如果被测文件不存在，realpath 返回 false
    } else {                                                // 模板文件特别注意预处理
        $tpath  = realpath($obt_conf['dir_tpl'] . $file);
        $cpath  = $obt_conf['dir_cpl'] . $file;
        if (strpos($tpath, $obt_conf['dir_tpl']) === 0      // 父目录限制
                && !$obt_conf['debug']                      // 调试模式时，不做模板预处理
                && $obt_conf['fun_cpl']                     // 预处理功能可选择性关闭
            ) {
            $ttime  = @filemtime($tpath);
            $ctime  = @filemtime($cpath);
            if ($ctime < $ttime) {
                $obt_conf['fun_cpl']($tpath, $cpath);       // 模板预处理，压缩空白、成分重组等
            }
            $tpath  = $cpath;
        }
    }
    return file_exists($tpath) ? $tpath : false;
}



function obt_data($obt_data = null, $except = array()) {    // 保存并整理模板数据变量
    global  $obt_vars;
    $obt_data   = $obt_data ?: $obt_vars['data'];
    $except[]   = 'obt_data';                               // obt_data 视为保留字，不得在模板中使用此变量名
    foreach ($except as $k) {
        unset($obt_data[$k]);
    }
    $obt_vars['data'] = array_merge($obt_vars['data'], $obt_data);
    return $obt_vars['data'];
}



function obt_block($name = null, $merge = '') {             // 片段开始，片段结束
    if ($name !== null) {
        obt_start($name, is_array($merge) ? $merge : array('merge' => $merge));
    } else {
        obt_endc();
    }
}



function obt_pfun($pfunction = null) {                      // 注册或执行父模板的懒函数
    global  $obt_vars;
    $pstack = &$obt_vars['pstack'];
    if ($pfunction) {                                       // 只注册，而不立即执行，所以叫懒函数
        $pstack[] = $pfunction;                             // 待子模板全部执行完成后，再依次向上逐级执行父模板
    } else {
        while ($pfunction = array_pop($pstack)) {
            obt_block('');                                  // 捕获泄漏的片段，对于根模板，这就是全部想要的输出
            $pfunction($obt_vars['data']);                  // 要求父模板导出全体模板变量以供更高一级父模板使用
            obt_block();
        }
    }
}



function obt_flush($name = '', $type = '', $callback = null) { // （获取）输出片段的指定成分
    global  $obt_vars;
    $type   = $type ?: 'htm';
    $flushs = obt_flush_r($obt_vars['blocks'][$name], $type);
    if (!$callback) {                                       // echo 输出
        foreach ($flushs as $item) {
            echo $item;
        }
    } else {
        if (!is_callable($callback)) {                      // 返回以小节为单位的片段内容
            return $flushs;
        }
        foreach ($flushs as $item) {                        // 以小节为单位，交给回调去处理
            $callback($item);
        }
    }
}



function obt_flush_r(&$block, $type) {                      // 深度优先，压平一个节点下的指定成分
    $flushs = array();
    $values = array();                                      // 未被压平的成分，按原样保留
    foreach ($block['value'] as &$item) {
        if (is_string($item)) {
            if ($type === 'htm') {
                $flushs[]   = $item;                        // 压平
            } else {
                $values[]   = $item;                        // 保留
            }
        } else if ($item['type'] === $type) {               // 压平
            $flushs[]   = is_string($item['value']) ? $item['value'] : implode('', $item['value']);
        } else {
            if ($item['type'] === 'block') {                // 深度优先
                $flushs = array_merge($flushs, obt_flush_r($item, $type));
            }
            $values[]   = $item;                            // 只压平节点下的成分，节点信息还是保留
        }
    }
    $block['value'] = $values;                              // 已被压平的成分，从片段内移除
    return $flushs;
}



function obt_start($name = '', $opts = array()) {           // 片段开始
    global  $obt_vars;
    global  $obt_conf;
    $bstack = &$obt_vars['bstack'];
    $blen   = count($bstack);
    if ($blen) {                                            // 这是一个被嵌套的内层片段
        $bstack[$blen - 1]['value'][] = trim(ob_get_contents());
        ob_clean();                                         // 内层开始前，先记录好外层已经产生的内容
    }
    $block  = array_merge(array(                            // 建立片段档案
            'name'  => $name,
            'type'  => 'block',
            'merge' => '',
            'value' => array(),                             // 一个片段可有多个小节，小节可能是另一个片段
        ), $opts);
    $block['debug'] = $block['debug'] || $obt_conf['debug'];
    $bstack[] = $block;                                     // 与嵌套的 ob_start 对应的栈，辅助生成树结构
    ob_start();
}



function obt_endc() {                                       // 片段结束
    global  $obt_vars;
    global  $obt_conf;
    $blocks = &$obt_vars['blocks'];
    $bstack = &$obt_vars['bstack'];
    $block  = &array_pop($bstack);                          // 片段结束时，取出其档案信息（于片段开始时创建）
    $name   = $block['name'];
    $block['value'][] = trim(ob_get_clean());
    if ($name && $blocks[$name]) {                          // 已有同名片段，则既有片段一定来自子模板
        $sublock = &$blocks[$name];
        if ($sublock['merge']   === 'over') {               // 按规则将子模板的相应内容，纳入到父模板的同名片段里
            $sublock['merge']   = '';
            $block['value']     = array();                  // 当子模板定义 over 时，父模板放弃其自身同名片段内容
        }
        $block['value'][]       = &$sublock;                // 如此可建立树型结构
    }
    $blocks[$name] = &$block;                               // 注意，无名片段都会被丢弃，只留最后一个
    $blen   = count($bstack);
    if ($blen) {                                            // 在当前片段的外层，还有其它尚未关闭的片段
        $bstack[$blen - 1]['value'][] = &$block;            // 如此可建立树型结构
    }
    if ($block['debug'] || !$obt_conf['fun_cpl']) {         // 调试模式 或 未做预处理，就要在运行时处理模板内容
        if ($block['name'] && $obt_conf['fun_bcc']) {
            $obt_conf['fun_bcc']($block);
        }
    }
}





/* 模板的本质，是用代码生成代码（执行 php 代码，输出 html 代码）
 *
 * 继承链上的模板代码，按执行流程分为两类：
 *      变量定义部分（父模板先定义，子模板后定义、读取、修改）
 *      代码输出部分（子模板先输出，父模板后输出、使用、读取子模板生成的内容与变量）
 * 按以上执行流程的特点，可以得到以下执行特性
 *      父模板可以定义、指定变量初始、默认值
 *      子模板可以读取、修改来自父模板的变量
 *      子模板对变量的修改，可以作用到父模板的输出部分
 */

/* 已知的问题，这只是一个极度简化的模型，有很多的注意点，需要人工处理
 *      没有模板级语法和语法检查，全依赖 php 语法和人工规范
 *      当子模板覆盖父模板同名片段时，父模板相关代码仍会执行
 *      所有的 include（或类似情况）都需要用 obt_file 处理文件名
 *      父模板要使用懒函数固定格式，限定的变量名和语句
 *
 *      style 片段内紧随 <?php 之后的换行符会被无条件删除（script 片段无此问题）
 *      style script 片段 tob 属性值不能用 block，编码依赖人工规范，注意使用分号
 *      block, obt_data, htm, ''(空字符串) 等保留字应慎用
 *
 *      block 可以嵌套，但 block_name 具有全局性，且 name 值不能是空字符串
 *      block_name 在同一继承级别内，不可重复定义，并列、嵌套均不允许
 *      有承级关系的同名片段，子模板的内容将默认追加到父模板内容之后，除非 merge = 'over'
 *      当子模板指定 merge = 'over' 时，上述过程由追加变为覆盖，但覆盖特性不会持续传递
 *      <style> 标签内部应该保持单纯，不能有 obt_block 等结构性操作，<script> 也是
 *
 */
