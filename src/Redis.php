<?php

namespace Aliene\Phalcon\Session;

use Phalcon\Session\AdapterInterface;

/**
 * @author Ian Brindley
 */
class Redis extends \SessionHandler implements AdapterInterface
{
    /**
     * Wait time (1ms) after first locking attempt. It doubles
     * for every unsuccessful retry until it either reaches
     * MAX_WAIT_TIME or succeeds.
     */
    const MIN_WAIT_TIME = 1000;

    /**
     * Maximum wait time (128ms) between locking attempts.
     */
    const MAX_WAIT_TIME = 128000;

    /**
     * Default options
     */
    const DEFAULT_OPTIONS = [
        "host" => "localhost",
        "port" => 6379,
        "auth" => null,
        "lifetime" => 3600,
        "database" => 0,
        "secret" => "secret",
        "prefix" => "SESSIONS:",
        "serializer" => "json_encode",
        "unserializer" => __CLASS__ . "::jsonDecodeArray",
        "id_mutator" => __CLASS__ . "::idMutator"
    ];

    /**
     * The Redis client.
     *
     * @var \Redis
     */
    private $redis;

    /**
     * User defined options derived from self::DEFAULT_OPTIONS
     *
     * @var mixed[]
     */
    private $options = [];

    /**
     * The maximum number of seconds that any given
     * session can remain locked. This is only meant
     * as a last resort releasing mechanism if for an
     * unknown reason the PHP engine never
     * calls Aliene\Phalcon\Session\Redis::close().
     *
     * $timeout is set to the 'max_execution_time'
     * runtime configuration value.
     *
     * @var int
     */
    private $timeout;

    /**
     * A collection of every session ID that has been generated
     * in the current thread of execution.
     *
     * This allows the handler to discern whether a given session ID
     * came from the HTTP request or was generated by the PHP engine
     * during the current thread of execution.
     *
     * @var string[]
     */
    private $new_sessions = [];

    /**
     * A collection of every session ID that is being locked by
     * the current thread of execution. When session_write_close()
     * is called the locks on all these IDs are removed.
     *
     * @var string[]
     */
    private $open_sessions = [];

    /**
     * The name of the session cookie.
     *
     * @var string
     */
    private $cookieName = "PHPSESSID";

    /**
     * @throws \RuntimeException When the phpredis extension is not available.
     */
    public function __construct(array $options = [])
    {
        if (false === extension_loaded('redis')) {
            throw new \RuntimeException("the 'redis' extension is needed in order to use this session handler");
        }

        $this->setOptions($options);

        $this->redis = new \Redis();
        $this->timeout = (int) ini_get("max_execution_time");

        ini_set("session.serialize_handler", "php_serialize");
        session_set_save_handler($this, true);
    }

    public static function idMutator ($id)
    {   
        return $id;
    }

    public static function jsonDecodeArray ($data)
    {
        return json_decode($data, true);
    }

    public function serializer ()
    { 
        return call_user_func_array($this->getOption("serializer"), func_get_args());
    }
    
    public function unserializer ()
    {
        return call_user_func_array($this->unserializer, func_get_args());
    }
    
    function id_mutator ()
    {
        return call_user_func_array($this->id_mutator, func_get_args());
    }
    
    /**
     * {@inheritdoc}
     */
    public function setOptions (array $options)
    {
        $this->options = array_replace_recursive($this->getOptions(), $options);
    }

    /**
     * {@inheritdoc}
     */
    public function getOptions ()
    {
        return array_replace_recursive(self::DEFAULT_OPTIONS, $this->options);
    }

    /**
     * {@inheritdoc}
     */
    public function getOption($option, $defaultValue = "")
    {
        return $this->options[$option] ?: $defaultValue;
    } 

    /**
     * {@inheritdoc}
     */
    public function get($index, $defaultValue = "")
    {
        return $_SESSION[$index] ?: $defaultValue;
    }

    /**
     * {@inheritdoc}
     */
    public function remove($index)
    {
        unset($_SESSION[$index]);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function set($index, $data)
    {
        $_SESSION[$index] = $data;
    }

    public function incr ($index) 
    {
        ++$_SESSION[$index];
        return $this;
    }

    public function decr ($index) 
    {
        --$_SESSION[$index];
        return $this;
    }

    /**
	 * Starts the session.
	 *
	 * @return boolean `true` if session successfully started
	 *         (or has already been started), `false` otherwise.
	 */
    public function start ()
    {
        if ($this->isStarted()) {
			return true;
        }
        
		return session_start();
    }

    /**
     * {@inheritdoc}
     */
    public function has ($index)
    {
        if (!$this->isStarted() && !$this->start()) {
			throw new RuntimeException('Could not start session.');
        }
        
		return isset($_SESSION[$index]);
    }

    /**
     * {@inheritdoc}
     */
    public function getId ()
    {
        return session_id();
    }
        
    /**
	 * Obtain the status of the session.
	 *
	 * @return boolean True if a session is currently started, False otherwise. If PHP 5.4
	 *                 then we know, if PHP 5.3 then we cannot tell for sure if a session
	 *                 has been closed.
	 */
    public function isStarted ()
    {
        if (function_exists('session_status')) {
            return session_status() === PHP_SESSION_ACTIVE;
        }
        return isset($_SESSION) && session_id();
    }

    /**
     * {@inheritdoc}
     */
    public function regenerateId ($deleteOldSession = false) {}
    
    /**
     * {@inheritdoc}
     */
    public function setName ($name) {}
    
    /**
     * {@inheritdoc}
     */
    public function getName () {}

    /**
     * {@inheritdoc}
     */
    public function open($save_path, $name)
    {
        $this->cookieName = $name;

        if (false === $this->redis->connect($this->getOption("host"), $this->getOption("port"))) {
            return false;
        }

        if ($auth = $this->getOption("auth")) {
            $this->redis->auth($auth);
        }

        $this->redis->select((int) $this->getOption("database"));

        $this->redis->setOption(\Redis::OPT_PREFIX, $this->getOption("prefix"));

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function create_sid()
    {
        $id = parent::create_sid();

        $this->new_sessions[$id] = true;

        return $id;
    }

    /**
     * {@inheritdoc}
     */
    public function read($session_id)
    {
        if ($this->mustRegenerate($session_id)) {
            session_id($session_id = $this->create_sid());
            $params = session_get_cookie_params();
            setcookie(
                $this->cookieName,
                $session_id,
                $params['lifetime'] ? time() + $params['lifetime'] : 0,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        $this->acquireLockOn($session_id);

        if ($this->isNew($session_id)) {
            return '';
        }

        return $this->redis->get($session_id);
    }

    /**
     * {@inheritdoc}
     */
    public function write($session_id, $session_data)
    {
        if (!$this->mustRegenerate($session_id)) {
            return $this->redis->setex($session_id, $this->getOption("lifetime"), $session_data);
        }
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function destroy($session_id = null)
    {
        if (!$session_id) {
            $session_id = $this->getId();
        }

        $this->redis->del($session_id);
        $this->redis->del("{$session_id}_lock");

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->releaseLocks();

        $this->redis->close();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function gc($maxlifetime)
    {
        // Redis does not need garbage collection, the builtin
        // expiration mechanism already takes care of stale sessions

        return true;
    }

    /**
     * @param string $session_id
     */
    private function acquireLockOn($session_id)
    {
        $options = ['nx'];
        if (0 < $this->timeout) {
            $options = ['nx', 'ex' => $this->timeout];
        }

        $wait = self::MIN_WAIT_TIME;
        while (false === $this->redis->set("{$session_id}_lock", '', $options)) {
            usleep($wait);

            if (self::MAX_WAIT_TIME > $wait) {
                $wait *= 2;
            }
        }

        $this->open_sessions[] = $session_id;
    }

    private function releaseLocks()
    {
        foreach ($this->open_sessions as $session_id) {
            $this->redis->del("{$session_id}_lock");
        }

        $this->open_sessions = [];
    }

    /**
     * A session ID must be regenerated when it came from the HTTP
     * request and can not be found in Redis.
     *
     * When that happens it either means that old session data expired in Redis
     * before the cookie with the session ID in the browser, or a malicious
     * client is trying to pull off a session fixation attack.
     *
     * @param string $session_id
     *
     * @return bool
     */
    private function mustRegenerate($session_id)
    {
        return false === $this->isNew($session_id)
            && false === (bool) $this->redis->exists($session_id);
    }

    /**
     * @param string $session_id
     *
     * @return bool
     */
    private function isNew($session_id)
    {
        return isset($this->new_sessions[$session_id]);
    }
}
