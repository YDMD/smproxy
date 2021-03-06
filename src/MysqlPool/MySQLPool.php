<?php

namespace SMProxy\MysqlPool;

use SMProxy\Log\Log;
use SMProxy\MysqlProxy;

/**
 * Author: Louis Livi <574747417@qq.com>
 * Date: 2018/11/6
 * Time: 上午10:52.
 */
class MySQLPool
{
    protected static $init = false;
    protected static $spareConns = [];
    protected static $busyConns = [];
    protected static $connsConfig;
    protected static $connsNameMap = [];
    protected static $pendingFetchCount = [];
    protected static $resumeFetchCount = [];
    protected static $yieldChannel = [];
    protected static $initConnCount = [];
    protected static $lastConnsTime = [];

    /**
     * @param array $connsConfig
     *
     * @throws MySQLException
     */
    public static function init(array $connsConfig)
    {
        if (self::$init) {
            return;
        }
        self::$connsConfig = $connsConfig;
        foreach ($connsConfig as $name => $config) {
            self::$spareConns[$name] = [];
            self::$busyConns[$name] = [];
            self::$pendingFetchCount[$name] = 0;
            self::$resumeFetchCount[$name] = 0;
            self::$initConnCount[$name] = 0;
            if ($config['maxSpareConns'] <= 0 || $config['maxConns'] <= 0) {
                $mysql_log = Log::getLogger('mysql');
                $mysql_log->warning("Invalid maxSpareConns or maxConns in {$name}");
                throw new MySQLException("Invalid maxSpareConns or maxConns in {$name}");
            }
        }
        self::$init = true;
    }

    /**
     * @param \Swoole\Coroutine\MySQL $conn
     *
     * @throws MySQLException
     */
    public static function recycle(MysqlProxy $conn)
    {
        if (!self::$init) {
            $mysql_log = Log::getLogger('mysql');
            $mysql_log->warning('Should call MySQLPool::init.');
            throw new MySQLException('Should call MySQLPool::init.');
        }
        $id = spl_object_hash($conn);
        $connName = self::$connsNameMap[$id];
        if (isset(self::$busyConns[$connName][$id])) {
            unset(self::$busyConns[$connName][$id]);
        } else {
            $mysql_log = Log::getLogger('mysql');
            $mysql_log->warning('Unknow MySQL connection.');
            throw new MySQLException('Unknow MySQL connection.');
        }
        $connsPool = &self::$spareConns[$connName];
        if ($conn->client->isConnected()) {
            if (((count($connsPool) + self::$initConnCount[$connName]) >=
                    self::$connsConfig[$connName]['maxSpareConns']) &&
                ((microtime(true) - self::$lastConnsTime[$id]) >= ((self::$connsConfig[$connName]['maxSpareExp']) ?? 0))
            ) {
                $conn->client->close();
            } else {
                $connsPool[] = $conn;
                if (self::$pendingFetchCount[$connName] > 0) {
                    ++self::$resumeFetchCount[$connName];
                    self::$yieldChannel[$connName]->push($id);
                }

                return;
            }
        }
        unset(self::$connsNameMap[$id]);
    }

    /**
     * 获取连接.
     *
     * @param $connName
     * @param \swoole_server $server
     * @param $fd
     *
     * @return bool|mixed|MysqlProxy
     *
     * @throws MySQLException
     * @throws \SMProxy\SMProxyException
     */
    public static function fetch(string $connName, \swoole_server $server, int $fd)
    {
        if (!self::$init) {
            $mysql_log = Log::getLogger('mysql');
            $mysql_log->warning('Should call MySQLPool::init!');
            throw new MySQLException('Should call MySQLPool::init!');
        }
        if (!isset(self::$connsConfig[$connName])) {
            $mysql_log = Log::getLogger('mysql');
            $mysql_log->warning("Unvalid connName: {$connName}.");
            throw new MySQLException("Unvalid connName: {$connName}.");
        }
        $connsPool = &self::$spareConns[$connName];
        if (!empty($connsPool) && count($connsPool) > self::$resumeFetchCount[$connName]) {
            $conn = array_pop($connsPool);
            if (!$conn->client->isConnected()) {
                return self::reconnect($server, $fd, $conn, $connName);
            } else {
                $conn->serverFd = $fd;
                $id = spl_object_hash($conn);
                self::$busyConns[$connName][$id] = $conn;
                self::$lastConnsTime[$id] = microtime(true);

                return $conn;
            }
        }
        if ((count(self::$busyConns[$connName]) + count($connsPool) + self::$pendingFetchCount[$connName] +
                self::$initConnCount[$connName]) >= self::$connsConfig[$connName]['maxConns']) {
            if (!isset(self::$yieldChannel[$connName])) {
                self::$yieldChannel[$connName] = new \Swoole\Coroutine\Channel(1);
            }
            ++self::$pendingFetchCount[$connName];
            $client = self::coPop(self::$yieldChannel[$connName], self::$connsConfig[$connName]['serverInfo']['timeout']);
            if (false === $client) {
                --self::$pendingFetchCount[$connName];
                $mysql_log = Log::getLogger('mysql');
                $mysql_log->warning('Reach max connections! Cann\'t pending fetch!');
                throw new MySQLException('Reach max connections! Cann\'t pending fetch!');
            }
            --self::$resumeFetchCount[$connName];
            if (!empty($connsPool)) {
                $conn = array_pop($connsPool);
                if (!$conn->client->isConnected()) {
                    $conn = self::reconnect($server, $fd, $conn, $connName);
                    --self::$pendingFetchCount[$connName];

                    return $conn;
                } else {
                    $conn->serverFd = $fd;
                    $id = spl_object_hash($conn);
                    self::$busyConns[$connName][$id] = $conn;
                    self::$lastConnsTime[$id] = microtime(true);
                    --self::$pendingFetchCount[$connName];

                    return $conn;
                }
            } else {
                return false; //should not happen
            }
        }

        return self::initConn($server, $fd, $connName);
    }

    /**
     * 初始化链接.
     *
     * @param \swoole_server $server
     * @param int            $fd
     * @param string         $connName
     *
     * @return mixed
     *
     * @throws MySQLException
     * @throws \SMProxy\SMProxyException
     */
    public static function initConn(\swoole_server $server, int $fd, string $connName)
    {
        ++self::$initConnCount[$connName];
        $chan = new \Swoole\Coroutine\Channel(1);
        $conn = new MysqlProxy($server, $fd, $chan);
        $serverInfo = self::$connsConfig[$connName]['serverInfo'];
        $database = false == strpos($connName, '_smproxy_') ? 0 : substr($connName, strpos($connName, '_smproxy_') + 9);
        $conn->database = $database;
        $conn->account = $serverInfo['account'];
        $conn->charset = self::$connsConfig[$connName]['charset'];
        if (false == $conn->connect(
            $serverInfo['host'],
            $serverInfo['port'],
            $serverInfo['timeout'] ?? 0.1
        )) {
            $mysql_log = Log::getLogger('mysql');
            $mysql_log->warning('Cann\'t connect to MySQL server: ' . json_encode($serverInfo));
            throw new MySQLException('Cann\'t connect to MySQL server: ' . json_encode($serverInfo));
        }
        $timeout_message = 'Connection ' . $serverInfo['host'] . ':' . $serverInfo['port'] .
            ' waiting timeout, timeout=' . $serverInfo['timeout'];
        $client = self::coPop($chan, $serverInfo['timeout']);
        if ($client === false) {
            --self::$initConnCount[$connName];
            $mysql_log = Log::getLogger('mysql');
            $mysql_log->warning($timeout_message);
            throw new MySQLException($timeout_message);
        }
        $id = spl_object_hash($client);
        self::$connsNameMap[$id] = $connName;
        self::$busyConns[$connName][$id] = $client;
        self::$lastConnsTime[$id] = microtime(true);
        --self::$initConnCount[$connName];

        return $client;
    }

    /**
     * 协程pop
     *
     * @param $chan
     * @param int $timeout
     *
     * @return bool
     */
    private static function coPop($chan, $timeout = 0)
    {
        if (version_compare(swoole_version(), '4.0.3', '>=')) {
            return $chan->pop($timeout);
        } else {
            if (0 == $timeout) {
                return $chan->pop();
            } else {
                $writes = [];
                $reads = [$chan];
                $result = $chan->select($reads, $writes, $timeout);
                if (false === $result || empty($reads)) {
                    return false;
                }

                $readChannel = $reads[0];

                return $readChannel->pop();
            }
        }
    }

    /**
     * 断重链.
     *
     * @param $server
     * @param $fd
     * @param $chan
     * @param $conn
     * @param $connName
     *
     * @return mixed
     *
     * @throws MySQLException
     * @throws \SMProxy\SMProxyException
     */
    public static function reconnect(\swoole_server $server, int $fd, MysqlProxy $conn, string $connName)
    {
        if (!$conn->client->isConnected()) {
            $old_id = spl_object_hash($conn);
            unset(self::$busyConns[$connName][$old_id]);
            unset(self::$connsNameMap[$old_id]);
            self::$lastConnsTime[$old_id] = 0;

            return self::initConn($server, $fd, $connName);
        }

        return $conn;
    }
}
