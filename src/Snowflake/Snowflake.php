<?php
namespace Kongflower\Snowflake;

/**
 * 雪花算法简单描述（生成递增id）
 *
 * 最高位是符号位，始终为0，不可用。
 * 41位的时间序列，精确到毫秒级，41位的长度可以使用69年。时间位还有一个很重要的作用是可以根据时间进行排序。
 * 10位的机器标识，10位的长度最多支持部署1024个节点。
 * 12位的计数序列号，序列号即一系列的自增id，可以支持同一节点同一毫秒生成多个ID序号，12位的计数序列号支持每个节点每毫秒产生4096个ID序号。
 * 看的出来，这个算法很简洁也很简单，但依旧是一个很好的ID生成策略。其中，10位器标识符一般是5位IDC+5位machine编号，唯一确定一台机器。
 */
final class Snowflake
{
    const TWEPOCH = 1584933040000; // 时间起始标记点，作为基准，一般取系统的最近时间（一旦确定不能变动）

    const WORKER_ID_BITS = 5; // 机器标识位数
    const DATACENTER_ID_BITS = 5; // 数据中心标识位数
    const SEQUENCE_BITS = 11; // 毫秒内自增位

    private $workerId; // 工作机器ID(0~31)
    private $datacenterId; // 数据中心ID(0~31)
    private $sequence; // 毫秒内序列(0~4095)

    private $maxWorkerId = -1 ^ (-1 << self::WORKER_ID_BITS); // 机器ID最大值31
    private $maxDatacenterId = -1 ^ (-1 << self::DATACENTER_ID_BITS); // 数据中心ID最大值31

    private $workerIdShift = self::SEQUENCE_BITS; // 机器ID偏左移11位
    private $datacenterIdShift = self::SEQUENCE_BITS + self::WORKER_ID_BITS; // 数据中心ID左移16位
    private $timestampLeftShift = self::SEQUENCE_BITS + self::WORKER_ID_BITS + self::DATACENTER_ID_BITS; // 时间毫秒左移21位
    private $sequenceMask = -1 ^ (-1 << self::SEQUENCE_BITS); // 生成序列的掩码4095

    private $lastTimestamp = -1; // 上次生产id时间戳

    /**
     * 私有静态属性用以保存对象
     */
    private static $instance;

    /**
     * 私有属性的克隆方法 防止被克隆
     */
    private function __clone()
    {}

    /**
     * 静态方法 用以实例化调用
     */
    public static function instance($workerId, $datacenterId, $sequence = 0)
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self($workerId, $datacenterId, $sequence);
        }
        return self::$instance;
    }

    /**
     * 私有属性的构造方法 防止被 new
     */
    private function __construct($workerId, $datacenterId, $sequence = 0)
    {
        //数学扩展否安装
        if (!extension_loaded('gmp')) {
            throw new \Exception("php-gmp extension is not install or open");
        }

        if (!extension_loaded('bcmath')) {
            throw new \Exception("php-bcmath extension is not install or open");
        }

        if ($workerId > $this->maxWorkerId || $workerId < 0) {
            throw new \Exception("worker Id can't be greater than {$this->maxWorkerId} or less than 0");
        }

        if ($datacenterId > $this->maxDatacenterId || $datacenterId < 0) {
            throw new \Exception("datacenter Id can't be greater than {$this->maxDatacenterId} or less than 0");
        }

        $this->workerId = $workerId;
        $this->datacenterId = $datacenterId;
        $this->sequence = $sequence;
    }

    /**
     * 生成分布式id
     */
    public function nextId()
    {
        $timestamp = $this->timeGen();

        if ($timestamp < $this->lastTimestamp) {
            $diffTimestamp = bcsub($this->lastTimestamp, $timestamp);
            throw new \Exception("Clock moved backwards.  Refusing to generate id for {$diffTimestamp} milliseconds");
        }

        if ($this->lastTimestamp == $timestamp) {
            $this->sequence = ($this->sequence + 1) & $this->sequenceMask;

            if (0 == $this->sequence) {
                $timestamp = $this->tilNextMillis($this->lastTimestamp);
            }
        } else {
            $this->sequence = 0;
        }

        $this->lastTimestamp = $timestamp;

        $gmpTimestamp = gmp_init($this->leftShift(bcsub($timestamp, self::TWEPOCH), $this->timestampLeftShift));
        $gmpDatacenterId = gmp_init($this->leftShift($this->datacenterId, $this->datacenterIdShift));
        $gmpWorkerId = gmp_init($this->leftShift($this->workerId, $this->workerIdShift));
        $gmpSequence = gmp_init($this->sequence);
        return gmp_strval(gmp_or(gmp_or(gmp_or($gmpTimestamp, $gmpDatacenterId), $gmpWorkerId), $gmpSequence));
    }

    protected function tilNextMillis($lastTimestamp)
    {
        $timestamp = $this->timeGen();
        while ($timestamp <= $lastTimestamp) {
            $timestamp = $this->timeGen();
        }

        return $timestamp;
    }

    /**
     * 获取微妙时间
     */
    protected function timeGen()
    {
        return floor(microtime(true) * 1000);
    }

    // 左移 <<
    protected function leftShift($a, $b)
    {
        return bcmul($a, bcpow(2, $b));
    }
}
