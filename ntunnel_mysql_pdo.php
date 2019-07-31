<?php
/**
 * 基于navicat官方的 ntunnel_mysql.php 修改而来，兼容PHP的PDO
 * User: zwjzxh520@gmail.com
 * Datetime: 2019-07-30 16:07:16
 */

$allowTestMenu = true;

$use_mysqli = function_exists("mysqli_connect");

header("Content-Type: text/plain; charset=x-user-defined");
error_reporting(0);
set_time_limit(0);

function phpversion_int()
{
    list($maVer, $miVer, $edVer) = preg_split("(/|\.|-)", phpversion());
    return $maVer * 10000 + $miVer * 100 + $edVer;
}

function GetLongBinary($num)
{
    return pack("N", $num);
}

function GetShortBinary($num)
{
    return pack("n", $num);
}

function GetDummy($count)
{
    $str = "";
    for ($i = 0; $i < $count; $i++)
        $str .= "\x00";
    return $str;
}

function GetBlock($val)
{
    $len = strlen($val);
    if ($len < 254)
        return chr($len) . $val;
    else
        return "\xFE" . GetLongBinary($len) . $val;
}

function EchoHeader($errno)
{
    $str = GetLongBinary(1111);
    $str .= GetShortBinary(202);
    $str .= GetLongBinary($errno);
    $str .= GetDummy(6);
    echo $str;
}

function EchoConnInfo($conn)
{
    /**
     * PDO::ATTR_AUTOCOMMIT: 1
     * PDO::ATTR_ERRMODE: 0
     * PDO::ATTR_CASE: 0
     * PDO::ATTR_CLIENT_VERSION: mysqlnd 5.0.12-dev - 20150407 - $Id: 3591daad22de08524295e1bd073aceeff11e6579 $
     * PDO::ATTR_CONNECTION_STATUS: db5 via TCP/IP
     * PDO::ATTR_ORACLE_NULLS: 0
     * PDO::ATTR_PERSISTENT:
     * PDO::ATTR_PREFETCH:
     * PDO::ATTR_SERVER_INFO: Uptime: 256447  Threads: 3  Questions: 3710  Slow queries: 0  Opens: 264  Flush tables: 1  Open tables: 257  Queries per second avg: 0.014
     * PDO::ATTR_SERVER_VERSION: 5.7.25
     * PDO::ATTR_TIMEOUT:
     */
    // Localhost via UNIX socket
    $str = GetBlock($conn->getAttribute(PDO::ATTR_CONNECTION_STATUS));
    //  MySQL protocol version: 10
    $str .= GetBlock($conn->getAttribute(PDO::ATTR_CLIENT_VERSION));
    // 4.1.2-alpha-debug
    $str .= GetBlock($conn->getAttribute(PDO::ATTR_SERVER_VERSION));
    echo $str;
}

function EchoResultSetHeader($errno, $affectrows, $insertid, $numfields, $numrows)
{
    $str = GetLongBinary($errno);
    $str .= GetLongBinary($affectrows);
    $str .= GetLongBinary($insertid);
    $str .= GetLongBinary($numfields);
    $str .= GetLongBinary($numrows);
    $str .= GetDummy(12);
    echo $str;
}

function EchoFieldsHeader($res, $numfields)
{
    $str = "";
    for ($i = 0; $i < $numfields; $i++) {

        $finfo = $res->getColumnMeta($i);
        $str .= GetBlock($finfo['name']);
        $str .= GetBlock($finfo['table']);
        // 与现有的类型不一致，需要优化
        $type = strtolower($finfo['native_type']);
        // php官方说 len 除了浮点型外，都是-1
        $length = $finfo['len'];
        switch ($type) {
            case "int":
                if ($length > 11) $type = 8;
                else $type = 3;
                break;
            case "real":
                if ($length == 12) $type = 4;
                elseif ($length == 22) $type = 5;
                else $type = 0;
                break;
            case "null":
                $type = 6;
                break;
            case "timestamp":
                $type = 7;
                break;
            case "date":
                $type = 10;
                break;
            case "time":
                $type = 11;
                break;
            case "datetime":
                $type = 12;
                break;
            case "year":
                $type = 13;
                break;
            case "blob":
                if ($length > 16777215) $type = 251;
                elseif ($length > 65535) $type = 250;
                elseif ($length > 255) $type = 252;
                else $type = 249;
                break;
            default:
                $type = 253;
        }
        $str .= GetLongBinary($type);

        $flags = $finfo['flags'];
        $intflag = 0;
        if (in_array("not_null", $flags)) $intflag += 1;
        if (in_array("primary_key", $flags)) $intflag += 2;
        if (in_array("unique_key", $flags)) $intflag += 4;
        if (in_array("multiple_key", $flags)) $intflag += 8;
        if (in_array("blob", $flags)) $intflag += 16;
        if (in_array("unsigned", $flags)) $intflag += 32;
        if (in_array("zerofill", $flags)) $intflag += 64;
        if (in_array("binary", $flags)) $intflag += 128;
        if (in_array("enum", $flags)) $intflag += 256;
        if (in_array("auto_increment", $flags)) $intflag += 512;
        if (in_array("timestamp", $flags)) $intflag += 1024;
        if (in_array("set", $flags)) $intflag += 2048;
        $str .= GetLongBinary($intflag);

        $str .= GetLongBinary($length);

    }
    echo $str;
}

function EchoData($res)
{
    while ($row = $res->fetch(PDO::FETCH_NUM)) {
        $str = "";

        foreach ($row as $item) {
            if (is_null($item))
                $str .= "\xFF";
            else
                $str .= GetBlock($item);
        }
        echo $str;
    }
}


function doSystemTest()
{
    function output($description, $succ, $resStr)
    {
        echo "<tr><td class=\"TestDesc\">$description</td><td ";
        echo ($succ) ? "class=\"TestSucc\">$resStr[0]</td></tr>" : "class=\"TestFail\">$resStr[1]</td></tr>";
    }

    output("PHP version >= 4.0.5", phpversion_int() >= 40005, ["Yes", "No"]);
    output("PDO available", class_exists("PDO"), ["Yes", "No"]);
    if (phpversion_int() >= 40302 && substr($_SERVER["SERVER_SOFTWARE"], 0, 6) == "Apache" && function_exists("apache_get_modules")) {
        if (in_array("mod_security2", apache_get_modules()))
            output("Mod Security 2 installed", false, ["No", "Yes"]);
    }
}

/////////////////////////////////////////////////////////////////////////////
////

if (phpversion_int() < 40005) {
    EchoHeader(201);
    echo GetBlock("unsupported php version");
    exit();
}

if (phpversion_int() < 40010) {
    global $HTTP_POST_VARS;
    $_POST = &$HTTP_POST_VARS;
}

$testMenu = false;
if (!isset($_POST["actn"]) || !isset($_POST["host"]) || !isset($_POST["port"]) || !isset($_POST["login"])) {
    $testMenu = $allowTestMenu;
    if (!$testMenu) {
        EchoHeader(202);
        echo GetBlock("invalid parameters");
        exit();
    }
}

if (!$testMenu) {
    if (isset($_POST["encodeBase64"]) && $_POST["encodeBase64"] == '1') {
        for ($i = 0; $i < count($_POST["q"]); $i++)
            $_POST["q"][$i] = base64_decode($_POST["q"][$i]);
    }


    if (!class_exists("PDO")) {
        EchoHeader(203);
        echo GetBlock("MySQL not supported on the server");
        exit();
    }

    if (!in_array('mysql', pdo_drivers())) {
        EchoHeader(203);
        echo GetBlock("pdo_mysql not install on the server");
        exit();
    }

    $errno = 0;
    $hs = $_POST["host"];

    try {
        $conn = new PDO("mysql:host=$hs;port={$_POST['port']}", $_POST["login"], $_POST["password"]);
    } catch (PDOException $e) {
        $errno = $e->getCode();
        $error = $e->getMessage();
    }
    if ($errno > 0) {
        EchoHeader($errno);
        echo GetBlock($error);
        exit;
    }

    if (($errno <= 0) && ($_POST["db"] != "")) {
        $conn->exec('use ' . $_POST["db"]);
        $errno = $conn->errorCode();
        if ($errno > 0) {
            echo GetBlock($conn->errorInfo());
        }
    }

    EchoHeader($errno);
    if ($_POST["actn"] == "C") {
        EchoConnInfo($conn);
    } elseif ($_POST["actn"] == "Q") {
        for ($i = 0; $i < count($_POST["q"]); $i++) {
            $query = $_POST["q"][$i];
            if ($query == "") continue;
            if (phpversion_int() < 50400) {
                if (get_magic_quotes_gpc())
                    $query = stripslashes($query);
            }

            $res = $conn->prepare($query);
            if ($res->execute()) {
                $numfields = $res->columnCount();
                $numrows = $res->rowCount();
                $affectedrows = $numrows;
                $insertid = $conn->lastInsertId();
                $errno = 0;
                $error = '';
            } else {
                $errorInfo = $res->errorInfo();
                $errno = $errorInfo[1];
                $error = $errorInfo[2];
                $numfields = $numrows = $affectedrows = $insertid = 0;
            }

            EchoResultSetHeader($errno, $affectedrows, $insertid, $numfields, $numrows);
            if ($errno > 0) {
                echo GetBlock($error);
            } else {
                if ($numfields > 0) {
                    EchoFieldsHeader($res, $numfields);
                    EchoData($res);
                } else {
                    echo GetBlock("");
                }
            }
            if ($i < (count($_POST["q"]) - 1))
                echo "\x01";
            else
                echo "\x00";
            // if (false !== $res)
            //     mysqli_free_result($res);
        }
    }
    exit();
}

header("Content-Type: text/html");
////
/////////////////////////////////////////////////////////////////////////////
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
<head>
    <title>Navicat HTTP Tunnel Tester</title>
    <meta http-equiv="Content-Type" content="text/html; charset=ISO-8859-1">
    <style type="text/css">
        body {
            margin: 30px;
            font-family: Tahoma;
            font-weight: normal;
            font-size: 14px;
            color: #222222;
        }

        table {
            width: 100%;
            border: 0px;
        }

        input {
            font-family: Tahoma, sans-serif;
            border-style: solid;
            border-color: #666666;
            border-width: 1px;
        }

        fieldset {
            border-style: solid;
            border-color: #666666;
            border-width: 1px;
        }

        .Title1 {
            font-size: 30px;
            color: #003366;
        }

        .Title2 {
            font-size: 10px;
            color: #999966;
        }

        .TestDesc {
            width: 70%
        }

        .TestSucc {
            color: #00BB00;
        }

        .TestFail {
            color: #DD0000;
        }

        .mysql {
        }

        .pgsql {
            display: none;
        }

        .sqlite {
            display: none;
        }

        #page {
            max-width: 42em;
            min-width: 36em;
            border-width: 0px;
            margin: auto auto;
        }

        #host, #dbfile {
            width: 300px;
        }

        #port {
            width: 75px;
        }

        #login, #password, #db {
            width: 150px;
        }

        #Copyright {
            text-align: right;
            font-size: 10px;
            color: #888888;
        }
    </style>
    <script type="text/javascript">
        function getInternetExplorerVersion() {
            var ver = -1;
            if (navigator.appName == "Microsoft Internet Explorer") {
                var regex = new RegExp("MSIE ([0-9]{1,}[\.0-9]{0,})");
                if (regex.exec(navigator.userAgent))
                    ver = parseFloat(RegExp.$1);
            }
            return ver;
        }

        function setText(element, text, succ) {
            element.className = (succ) ? "TestSucc" : "TestFail";
            element.innerHTML = text;
        }

        function getByteAt(str, offset) {
            return str.charCodeAt(offset) & 0xff;
        }

        function getIntAt(binStr, offset) {
            return (getByteAt(binStr, offset) << 24) +
                (getByteAt(binStr, offset + 1) << 16) +
                (getByteAt(binStr, offset + 2) << 8) +
                (getByteAt(binStr, offset + 3) >>> 0);
        }

        function getBlockStr(binStr, offset) {
            if (getByteAt(binStr, offset) < 254)
                return binStr.substring(offset + 1, offset + 1 + binStr.charCodeAt(offset));
            else
                return binStr.substring(offset + 5, offset + 5 + getIntAt(binStr, offset + 1));
        }

        function doServerTest() {
            var version = getInternetExplorerVersion();
            if (version == -1 || version >= 9.0) {
                var xmlhttp = (window.XMLHttpRequest) ? new XMLHttpRequest() : xmlhttp = new ActiveXObject("Microsoft.XMLHTTP");

                xmlhttp.onreadystatechange = function () {
                    var outputDiv = document.getElementById("ServerTest");
                    if (xmlhttp.readyState == 4) {
                        if (xmlhttp.status == 200) {
                            var errno = getIntAt(xmlhttp.responseText, 6);
                            if (errno == 0)
                                setText(outputDiv, "Connection Success!", true);
                            else
                                setText(outputDiv, parseInt(errno) + " - " + getBlockStr(xmlhttp.responseText, 16), false);
                        } else
                            setText(outputDiv, "HTTP Error - " + xmlhttp.status, false);
                    }
                }

                var params = "";
                var form = document.getElementById("TestServerForm");
                for (var i = 0; i < form.elements.length; i++) {
                    if (i > 0) params += "&";
                    params += form.elements[i].id + "=" + form.elements[i].value.replace("&", "%26");
                }

                document.getElementById("ServerTest").className = "";
                document.getElementById("ServerTest").innerHTML = "Connecting...";
                xmlhttp.open("POST", "", true);
                xmlhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
                xmlhttp.setRequestHeader("Content-length", params.length);
                xmlhttp.setRequestHeader("Connection", "close");
                xmlhttp.send(params);
            } else {
                document.getElementById("ServerTest").className = "";
                document.getElementById("ServerTest").innerHTML = "Internet Explorer " + version + " is not supported, please use Internet explorer 9.0 or above, firefox, chrome or safari";
            }
        }
    </script>
</head>

<body>
<div id="page">
    <p>
        <font class="Title1">Navicat&trade;</font><br>
        <font class="Title2">The gateway to your database!</font>
    </p>
    <fieldset>
        <legend>System Environment Test</legend>
        <table>
            <tr style="<?php echo "display:none"; ?>">
                <td width=70%>PHP installed properly</td>
                <td class="TestFail">No</td>
            </tr>
            <?php echo doSystemTest(); ?>
        </table>
    </fieldset>
    <br>
    <fieldset>
        <legend>Server Test</legend>
        <form id="TestServerForm" action="#" onSubmit="return false;">
            <input type=hidden id="actn" value="C">
            <table>
                <tr class="mysql">
                    <td width="35%">Hostname/IP Address:</td>
                    <td><input type=text id="host" placeholder="localhost"></td>
                </tr>
                <tr class="mysql">
                    <td>Port:</td>
                    <td><input type=text id="port" placeholder="3306"></td>
                </tr>
                <tr class="pgsql">
                    <td>Initial Database:</td>
                    <td><input type=text id="db" placeholder="template1"></td>
                </tr>
                <tr class="mysql">
                    <td>Username:</td>
                    <td><input type=text id="login" placeholder="root"></td>
                </tr>
                <tr class="mysql">
                    <td>Password:</td>
                    <td><input type=password id="password" placeholder=""></td>
                </tr>
                <tr class="sqlite">
                    <td>Database File:</td>
                    <td><input type=text id="dbfile" placeholder="sqlite.db"></td>
                </tr>
                <tr>
                    <td></td>
                    <td><br><input id="TestButton" type="submit" value="Test Connection" onClick="doServerTest()"></td>
                </tr>
            </table>
        </form>
        <div id="ServerTest"><br></div>
    </fieldset>
    <p id="Copyright">Copyright &copy; PremiumSoft &trade; CyberTech Ltd. All Rights Reserved.</p>
</div>
</body>
</html>