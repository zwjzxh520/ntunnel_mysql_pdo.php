# ntunnel_mysql_pdo.php
navicat的http通道文件ntunnel_mysql.php，升级为支持pdo

## 可能的问题：
```php
// 原代码
$type = mysql_field_type($res, $i);
$length = mysql_field_len($res, $i);

// 新代码
$res->getColumnMeta($i);
// 与现有的类型不一致，需要优化
$type = strtotime($finfo['native_type']);
// php官方说 len 除了浮点型外，都是-1
$length = $finfo['len'];
```
1. 列的元数据中，类型不一致
2. 列的元数据中，len长度不一致


欢迎大家提交 pull requests
