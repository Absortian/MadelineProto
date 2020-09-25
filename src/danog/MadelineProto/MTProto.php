<?php

/**
 * MTProto module.
 *
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2020 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 *
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto;

use Amp\Dns\Resolver;
use Amp\File\StatCache;
use Amp\Http\Client\HttpClient;
use Amp\Loop;
use Amp\Promise;
use Amp\Success;
use Closure;
use danog\MadelineProto\Async\AsyncConstruct;
use danog\MadelineProto\Db\DbArray;
use danog\MadelineProto\Db\DbPropertiesFactory;
use danog\MadelineProto\Db\DbPropertiesTrait;
use danog\MadelineProto\Db\MemoryArray;
use danog\MadelineProto\Ipc\Server;
use danog\MadelineProto\Loop\Generic\PeriodicLoopInternal;
use danog\MadelineProto\Loop\Update\FeedLoop;
use danog\MadelineProto\Loop\Update\SeqLoop;
use danog\MadelineProto\Loop\Update\UpdateLoop;
use danog\MadelineProto\MTProtoTools\CombinedUpdatesState;
use danog\MadelineProto\MTProtoTools\GarbageCollector;
use danog\MadelineProto\MTProtoTools\MinDatabase;
use danog\MadelineProto\MTProtoTools\ReferenceDatabase;
use danog\MadelineProto\MTProtoTools\UpdatesState;
use danog\MadelineProto\Settings\Database\Memory;
use danog\MadelineProto\Settings\TLSchema;
use danog\MadelineProto\TL\TL;
use danog\MadelineProto\TL\TLCallback;

use function Amp\File\exists;
use function Amp\File\size;

/**
 * Manages all of the mtproto stuff.
 */
class MTProto extends AsyncConstruct implements TLCallback
{
    use \danog\Serializable;
    use \danog\MadelineProto\MTProtoTools\AuthKeyHandler;
    use \danog\MadelineProto\MTProtoTools\CallHandler;
    use \danog\MadelineProto\MTProtoTools\PeerHandler;
    use \danog\MadelineProto\MTProtoTools\UpdateHandler;
    use \danog\MadelineProto\MTProtoTools\Files;
    use \danog\MadelineProto\SecretChats\AuthKeyHandler;
    use \danog\MadelineProto\SecretChats\MessageHandler;
    use \danog\MadelineProto\SecretChats\ResponseHandler;
    use \danog\MadelineProto\SecretChats\SeqNoHandler;
    use \danog\MadelineProto\TL\Conversion\BotAPI;
    use \danog\MadelineProto\TL\Conversion\BotAPIFiles;
    use \danog\MadelineProto\TL\Conversion\Extension;
    use \danog\MadelineProto\TL\Conversion\TD;
    use \danog\MadelineProto\VoIP\AuthKeyHandler;
    use \danog\MadelineProto\Wrappers\DialogHandler;
    use \danog\MadelineProto\Wrappers\Events;
    use \danog\MadelineProto\Wrappers\Webhook;
    use \danog\MadelineProto\Wrappers\Callback;
    use \danog\MadelineProto\Wrappers\Login;
    use \danog\MadelineProto\Wrappers\Loop;
    use \danog\MadelineProto\Wrappers\Noop;
    use \danog\MadelineProto\Wrappers\Start;
    use \danog\MadelineProto\Wrappers\Templates;
    use \danog\MadelineProto\Wrappers\TOS;
    use DbPropertiesTrait;
    /**
     * Old internal version of MadelineProto.
     *
     * DO NOT REMOVE THIS COMMENTED OUT CONSTANT
     *
     * @var int
     */
    /*
        const V = 71;
    */
    /**
     * Internal version of MadelineProto.
     *
     * Increased every time the default settings array or something big changes
     *
     * @var int
     */
    const V = 147;
    /**
     * String release version.
     *
     * @var string
     */
    const RELEASE = '5.0';
    /**
     * We're not logged in.
     *
     * @var int
     */
    const NOT_LOGGED_IN = 0;
    /**
     * We're waiting for the login code.
     *
     * @var int
     */
    const WAITING_CODE = 1;
    /**
     * We're waiting for parameters to sign up.
     *
     * @var int
     */
    const WAITING_SIGNUP = -1;
    /**
     * We're waiting for the 2FA password.
     *
     * @var int
     */
    const WAITING_PASSWORD = 2;
    /**
     * We're logged in.
     *
     * @var int
     */
    const LOGGED_IN = 3;
    /**
     * Bad message error codes.
     *
     * @var array
     */
    const BAD_MSG_ERROR_CODES = [16 => 'msg_id too low (most likely, client time is wrong; it would be worthwhile to synchronize it using msg_id notifications and re-send the original message with the â€œcorrectâ€ msg_id or wrap it in a container with a new msg_id if the original message had waited too long on the client to be transmitted)', 17 => 'msg_id too high (similar to the previous case, the client time has to be synchronized, and the message re-sent with the correct msg_id)', 18 => 'incorrect two lower order msg_id bits (the server expects client message msg_id to be divisible by 4)', 19 => 'container msg_id is the same as msg_id of a previously received message (this must never happen)', 20 => 'message too old, and it cannot be verified whether the server has received a message with this msg_id or not', 32 => 'msg_seqno too low (the server has already received a message with a lower msg_id but with either a higher or an equal and odd seqno)', 33 => 'msg_seqno too high (similarly, there is a message with a higher msg_id but with either a lower or an equal and odd seqno)', 34 => 'an even msg_seqno expected (irrelevant message), but odd received', 35 => 'odd msg_seqno expected (relevant message), but even received', 48 => 'incorrect server salt (in this case, the bad_server_salt response is received with the correct salt, and the message is to be re-sent with it)', 64 => 'invalid container'];
    /**
     * Localized message info flags.
     *
     * @var array
     */
    const MSGS_INFO_FLAGS = [1 => 'nothing is known about the message (msg_id too low, the other party may have forgotten it)', 2 => 'message not received (msg_id falls within the range of stored identifiers; however, the other party has certainly not received a message like that)', 3 => 'message not received (msg_id too high; however, the other party has certainly not received it yet)', 4 => 'message received (note that this response is also at the same time a receipt acknowledgment)', 8 => ' and message already acknowledged', 16 => ' and message not requiring acknowledgment', 32 => ' and RPC query contained in message being processed or processing already complete', 64 => ' and content-related response to message already generated', 128 => ' and other party knows for a fact that message is already received'];
    /**
     * Secret chat was not found.
     *
     * @var int
     */
    const SECRET_EMPTY = 0;
    /**
     * Secret chat was requested.
     *
     * @var int
     */
    const SECRET_REQUESTED = 1;
    /**
     * Secret chat was found.
     *
     * @var int
     */
    const SECRET_READY = 2;
    const GETUPDATES_HANDLER = 'getUpdates';
    const TD_PARAMS_CONVERSION = ['updateNewMessage' => ['_' => 'updateNewMessage', 'disable_notification' => ['message', 'silent'], 'message' => ['message']], 'message' => ['_' => 'message', 'id' => ['id'], 'sender_user_id' => ['from_id'], 'chat_id' => ['to_id', 'choose_chat_id_from_botapi'], 'send_state' => ['choose_incoming_or_sent'], 'can_be_edited' => ['choose_can_edit'], 'can_be_deleted' => ['choose_can_delete'], 'is_post' => ['post'], 'date' => ['date'], 'edit_date' => ['edit_date'], 'forward_info' => ['fwd_info', 'choose_forward_info'], 'reply_to_message_id' => ['reply_to_msg_id'], 'ttl' => ['choose_ttl'], 'ttl_expires_in' => ['choose_ttl_expires_in'], 'via_bot_user_id' => ['via_bot_id'], 'views' => ['views'], 'content' => ['choose_message_content'], 'reply_markup' => ['reply_markup']], 'messages.sendMessage' => ['chat_id' => ['peer'], 'reply_to_message_id' => ['reply_to_msg_id'], 'disable_notification' => ['silent'], 'from_background' => ['background'], 'input_message_content' => ['choose_message_content'], 'reply_markup' => ['reply_markup']]];
    const TD_REVERSE = ['sendMessage' => 'messages.sendMessage'];
    const TD_IGNORE = ['updateMessageID'];
    const BOTAPI_PARAMS_CONVERSION = ['disable_web_page_preview' => 'no_webpage', 'disable_notification' => 'silent', 'reply_to_message_id' => 'reply_to_msg_id', 'chat_id' => 'peer', 'text' => 'message'];
    // Not content related constructors
    const NOT_CONTENT_RELATED = [
        //'rpc_result',
        //'rpc_error',
        'rpc_drop_answer',
        'rpc_answer_unknown',
        'rpc_answer_dropped_running',
        'rpc_answer_dropped',
        'get_future_salts',
        'future_salt',
        'future_salts',
        'ping',
        'pong',
        'ping_delay_disconnect',
        'destroy_session',
        'destroy_session_ok',
        'destroy_session_none',
        //'new_session_created',
        'msg_container',
        'msg_copy',
        'gzip_packed',
        'http_wait',
        'msgs_ack',
        'bad_msg_notification',
        'bad_server_salt',
        'msgs_state_req',
        'msgs_state_info',
        'msgs_all_info',
        'msg_detailed_info',
        'msg_new_detailed_info',
        'msg_resend_req',
        'msg_resend_ans_req',
    ];
    const DEFAULT_GETUPDATES_PARAMS = ['offset' => 0, 'limit' => null, 'timeout' => 100];
    /**
     * Instance of wrapper API.
     *
     * @var APIWrapper
     */
    public $wrapper;
    /**
     * PWRTelegram webhook URL.
     *
     * @var boolean|string
     */
    public $hook_url = false;
    /**
     * Settings array.
     *
     * @var Settings
     */
    public $settings;
    /**
     * Config array.
     *
     * @var array
     */
    private $config = ['expires' => -1];
    /**
     * TOS info.
     *
     * @var array
     */
    private $tos = ['expires' => 0, 'accepted' => true];
    /**
     * Whether we're initing authorization.
     *
     * @var boolean
     */
    private $initing_authorization = false;
    /**
     * Authorization info (User).
     *
     * @var array|null
     */
    public $authorization = null;
    /**
     * Whether we're authorized.
     *
     * @var integer
     */
    public $authorized = self::NOT_LOGGED_IN;
    /**
     * Main authorized DC ID.
     *
     * @var integer
     */
    public $authorized_dc = -1;
    /**
     * RSA keys.
     *
     * @var array<RSA>
     */
    private $rsa_keys = [];
    /**
     * CDN RSA keys.
     *
     * @var array
     */
    private $cdn_rsa_keys = [];
    /**
     * Diffie-hellman config.
     *
     * @var array
     */
    private $dh_config = ['version' => 0];
    /**
     * Internal peer database.
     *
     * @var DbArray|Promise[]
     */
    public $chats;

    /**
     * Cache of usernames for chats.
     *
     * @var DbArray|Promise[]
     */
    public $usernames;
    /**
     * Cached parameters for fetching channel participants.
     *
     * @var DbArray|Promise[]
     */
    public $channel_participants;
    /**
     * When we last stored data in remote peer database (now doesn't exist anymore).
     *
     * @var integer
     */
    public $last_stored = 0;
    /**
     * Temporary array of data to be sent to remote peer database.
     *
     * @var array
     */
    public $qres = [];
    /**
     * Full chat info database.
     *
     * @var DbArray|Promise[]
     */
    public $full_chats;
    /**
     * Latest chat message ID map for update handling.
     *
     * @var array
     */
    private $msg_ids = [];
    /**
     * Version integer for upgrades.
     *
     * @var integer
     */
    private $v = 0;
    /**
     * Cached getdialogs params.
     *
     * @var array
     */
    private $dialog_params = ['limit' => 0, 'offset_date' => 0, 'offset_id' => 0, 'offset_peer' => ['_' => 'inputPeerEmpty'], 'count' => 0];
    /**
     * Support user ID.
     *
     * @var integer
     */
    private $supportUser = 0;
    /**
     * File reference database.
     *
     * @var \danog\MadelineProto\MTProtoTools\ReferenceDatabase
     */
    public $referenceDatabase;
    /**
     * min database.
     *
     * @var \danog\MadelineProto\MTProtoTools\MinDatabase
     */
    public $minDatabase;
    /**
     * TOS check loop.
     */
    public ?PeriodicLoopInternal $checkTosLoop = null;
    /**
     * Phone config loop.
     */
    public ?PeriodicLoopInternal $phoneConfigLoop = null;
    /**
     * Config loop.
     */
    public ?PeriodicLoopInternal  $configLoop = null;
    /**
     * Call checker loop.
     */
    private ?PeriodicLoopInternal $callCheckerLoop = null;
    /**
     * Autoserialization loop.
     */
    private ?PeriodicLoopInternal $serializeLoop = null;
    /**
     * RPC reporting loop.
     */
    private ?PeriodicLoopInternal $rpcLoop = null;
    /**
     * IPC server.
     */
    private ?Server $ipcServer = null;
    /**
     * Feeder loops.
     *
     * @var array<\danog\MadelineProto\Loop\Update\FeedLoop>
     */
    public $feeders = [];
    /**
     * Updater loops.
     *
     * @var array<\danog\MadelineProto\Loop\Update\UpdateLoop>
     */
    public $updaters = [];
    /**
     * Boolean to avoid problems with exceptions thrown by forked strands, see tools.
     *
     * @var boolean
     */
    public bool $destructing = false;
    /**
     * DataCenter instance.
     *
     * @var DataCenter
     */
    public $datacenter;
    /**
     * Logger instance.
     *
     * @var Logger
     */
    public $logger;
    /**
     * TL serializer.
     *
     * @var \danog\MadelineProto\TL\TL
     */
    private $TL;

    /**
     * Snitch.
     */
    private Snitch $snitch;

    /**
     * DC list.
     */
    protected array $dcList = [
        'test' => [
            // Test datacenters
            'ipv4' => [
                // ipv4 addresses
                2 => [
                    // The rest will be fetched using help.getConfig
                    'ip_address' => '149.154.167.40',
                    'port' => 443,
                    'media_only' => false,
                    'tcpo_only' => false,
                ],
            ],
            'ipv6' => [
                // ipv6 addresses
                2 => [
                    // The rest will be fetched using help.getConfig
                    'ip_address' => '2001:067c:04e8:f002:0000:0000:0000:000e',
                    'port' => 443,
                    'media_only' => false,
                    'tcpo_only' => false,
                ],
            ],
        ],
        'main' => [
            // Main datacenters
            'ipv4' => [
                // ipv4 addresses
                2 => [
                    // The rest will be fetched using help.getConfig
                    'ip_address' => '149.154.167.51',
                    'port' => 443,
                    'media_only' => false,
                    'tcpo_only' => false,
                ],
            ],
            'ipv6' => [
                // ipv6 addresses
                2 => [
                    // The rest will be fetched using help.getConfig
                    'ip_address' => '2001:067c:04e8:f002:0000:0000:0000:000a',
                    'port' => 443,
                    'media_only' => false,
                    'tcpo_only' => false,
                ],
            ],
        ]
    ];

    /**
     * Nullcache array for storing main session file to DB.
     *
     * @var DbArray|Promise[]
     */
    public $session;

    /**
     * List of properties stored in database (memory or external).
     * @see DbPropertiesFactory
     * @var array
     */
    protected static array $dbProperties = [
        'chats' => 'array',
        'full_chats' => 'array',
        'channel_participants' => 'array',
        'usernames' => 'array',
        'session' => 'arrayNullCache'
    ];

    /**
     * Serialize session, returning object to serialize to db.
     *
     * @return \Generator
     */
    public function serializeSession(object $data): \Generator
    {
        if (!$this->session || $this->session instanceof MemoryArray) {
            return $data;
        }
        yield $this->session['data'] = \serialize($data);
        return $this->session;
    }

    /**
     * Constructor function.
     *
     * @param Settings|SettingsEmpty $settings Settings
     * @param APIWrapper             $wrapper  API wrapper
     *
     * @return void
     */
    public function __magic_construct(SettingsAbstract $settings, APIWrapper $wrapper)
    {
        $this->wrapper = $wrapper;
        $this->setInitPromise($this->__construct_async($settings));
    }
    /**
     * Async constructor function.
     *
     * @param Settings|SettingsEmpty $settings Settings
     *
     * @return \Generator
     */
    public function __construct_async(SettingsAbstract $settings): \Generator
    {
        // Initialize needed stuffs
        Magic::classExists();
        // Parse and store settings
        yield from $this->updateSettings($settings, false);
        // Actually instantiate needed classes like a boss
        $this->logger->logger(Lang::$current_lang['inst_dc'], Logger::ULTRA_VERBOSE);
        yield from $this->cleanupProperties();
        // Load rsa keys
        $this->logger->logger(Lang::$current_lang['load_rsa'], Logger::ULTRA_VERBOSE);
        $this->rsa_keys = [];
        foreach ($this->settings->getAuth()->getRsaKeys() as $key) {
            $key = (yield from (new RSA())->load($this->TL, $key));
            $this->rsa_keys[$key->fp] = $key;
        }
        // (re)-initialize TL
        $this->logger->logger(Lang::$current_lang['TL_translation'], Logger::ULTRA_VERBOSE);
        $callbacks = [$this, $this->referenceDatabase];
        if (!($this->authorization['user']['bot'] ?? false)) {
            $callbacks[] = $this->minDatabase;
        }
        $this->TL->init($this->settings->getSchema(), $callbacks);
        yield from $this->connectToAllDcs();
        $this->startLoops();
        $this->datacenter->curdc = 2;
        if ((!isset($this->authorization['user']['bot']) || !$this->authorization['user']['bot']) && $this->datacenter->getDataCenterConnection($this->datacenter->curdc)->hasTempAuthKey()) {
            try {
                $nearest_dc = yield from $this->methodCallAsyncRead('help.getNearestDc', [], ['datacenter' => $this->datacenter->curdc]);
                $this->logger->logger(\sprintf(Lang::$current_lang['nearest_dc'], $nearest_dc['country'], $nearest_dc['nearest_dc']), Logger::NOTICE);
                if ($nearest_dc['nearest_dc'] != $nearest_dc['this_dc']) {
                    $this->settings->setDefaultDc($this->datacenter->curdc = (int) $nearest_dc['nearest_dc']);
                }
            } catch (RPCErrorException $e) {
                if ($e->rpc !== 'BOT_METHOD_INVALID') {
                    throw $e;
                }
            }
        }
        yield from $this->getConfig([], ['datacenter' => $this->datacenter->curdc]);
        $this->startUpdateSystem(true);
        $this->v = self::V;

        GarbageCollector::start();
    }
    /**
     * Set API wrapper needed for triggering serialization functions.
     */
    public function setWrapper(APIWrapper $wrapper): void
    {
        $this->wrapper = $wrapper;
    }
    /**
     * Sleep function.
     *
     * @return array
     */
    public function __sleep(): array
    {
        $db = $this->settings->getDb();
        if ($db instanceof Memory && $db->getCleanup()) {
            $this->cleanup();
        }
        $res = [
            // Databases
            'chats',
            'full_chats',
            'referenceDatabase',
            'minDatabase',
            'channel_participants',
            'usernames',

            // Misc caching
            'dialog_params',
            'last_stored',
            'qres',
            'supportUser',
            'tos',

            // Event handler
            'event_handler',
            'event_handler_instance',
            'loop_callback',
            'updates',
            'updates_key',
            'hook_url',

            // Web login template
            'web_template',

            // Settings
            'settings',
            'config',
            'dcList',

            // Authorization keys
            'datacenter',

            // Authorization state
            'authorization',
            'authorized',
            'authorized_dc',

            // Authorization cache
            'rsa_keys',
            'dh_config',

            // Update state
            'got_state',
            'channels_state',
            'msg_ids',

            // Version
            'v',

            // TL
            'TL',

            // Secret chats
            'secret_chats',
            'temp_requested_secret_chats',
            'temp_rekeyed_secret_chats',

            // Report URI
            'reportDest',
        ];
        if (!$this->updateHandler instanceof Closure) {
            $res[] = 'updateHandler';
        }
        return $res;
    }

    /**
     * Cleanup memory and session file.
     *
     * @return void
     */
    public function cleanup(): void
    {
        $this->referenceDatabase = new ReferenceDatabase($this);
        $callbacks = [$this, $this->referenceDatabase];
        if (!($this->authorization['user']['bot'] ?? false)) {
            $callbacks[] = $this->minDatabase;
        }
        $this->TL->updateCallbacks($callbacks);
    }

    private function fillUsernamesCache(): \Generator
    {
        if (yield $this->usernames->count() === 0) {
            $this->logger('Filling database cache. This can take few minutes.', Logger::WARNING);
            $iterator = $this->chats->getIterator();
            while (yield $iterator->advance()) {
                [$id, $chat] = $iterator->getCurrent();
                if (isset($chat['username'])) {
                    $this->usernames[\strtolower($chat['username'])] = $this->getId($chat);
                }
            }
            $this->logger('Cache filled.', Logger::WARNING);
        }
    }

    /**
     * Logger.
     *
     * @param string $param Parameter
     * @param int    $level Logging level
     * @param string $file  File where the message originated
     *
     * @return void
     */
    public function logger($param, int $level = Logger::NOTICE, string $file = ''): void
    {
        if ($file === null) {
            $file = \basename(\debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'], '.php');
        }
        isset($this->logger) ? $this->logger->logger($param, $level, $file) : Logger::$default->logger($param, $level, $file);
    }
    /**
     * Get TL namespaces.
     *
     * @return array
     */
    public function getMethodNamespaces(): array
    {
        return $this->TL->getMethodNamespaces();
    }
    /**
     * Get namespaced methods (method => namespace).
     *
     * @return array
     */
    public function getMethodsNamespaced(): array
    {
        return $this->TL->getMethodsNamespaced();
    }
    /**
     * Get TL serializer.
     *
     * @return TL
     */
    public function getTL(): \danog\MadelineProto\TL\TL
    {
        return $this->TL;
    }
    /**
     * Get logger.
     *
     * @return Logger
     */
    public function getLogger(): Logger
    {
        return $this->logger;
    }
    /**
     * Get async HTTP client.
     *
     * @return \Amp\Http\Client\HttpClient
     */
    public function getHTTPClient(): HttpClient
    {
        return $this->datacenter->getHTTPClient();
    }
    /**
     * Get async DNS client.
     *
     * @return \Amp\Dns\Resolver
     */
    public function getDNSClient(): Resolver
    {
        return $this->datacenter->getDNSClient();
    }
    /**
     * Get contents of remote file asynchronously.
     *
     * @param string $url URL
     *
     * @return \Generator<string>
     */
    public function fileGetContents(string $url): \Generator
    {
        return $this->datacenter->fileGetContents($url);
    }
    /**
     * Get all datacenter connections.
     *
     * @return array<DataCenterConnection>
     */
    public function getDataCenterConnections(): array
    {
        return $this->datacenter->getDataCenterConnections();
    }
    /**
     * Get main DC ID.
     *
     * @return int
     */
    public function getDataCenterId(): int
    {
        return $this->datacenter->curdc;
    }
    /**
     * Prompt serialization of instance.
     *
     * @internal
     *
     * @return void
     */
    public function serialize()
    {
        if ($this->wrapper && $this->inited()) {
            $this->wrapper->serialize();
        }
    }
    /**
     * Start all internal loops.
     *
     * @return void
     */
    private function startLoops()
    {
        if (!$this->callCheckerLoop) {
            $this->callCheckerLoop = new PeriodicLoopInternal($this, [$this, 'checkCalls'], 'call check', 10 * 1000);
        }
        if (!$this->serializeLoop) {
            $this->serializeLoop = new PeriodicLoopInternal($this, [$this, 'serialize'], 'serialize', $this->settings->getSerialization()->getInterval() * 1000);
        }
        if (!$this->phoneConfigLoop) {
            $this->phoneConfigLoop = new PeriodicLoopInternal($this, [$this, 'getPhoneConfig'], 'phone config', 24 * 3600 * 1000);
        }
        if (!$this->checkTosLoop) {
            $this->checkTosLoop = new PeriodicLoopInternal($this, [$this, 'checkTos'], 'TOS', 24 * 3600 * 1000);
        }
        if (!$this->configLoop) {
            $this->configLoop = new PeriodicLoopInternal($this, [$this, 'getConfig'], 'config', 24 * 3600 * 1000);
        }
        if (!$this->rpcLoop) {
            $this->rpcLoop = new PeriodicLoopInternal($this, [$this, 'rpcReport'], 'config', 60 * 1000);
        }
        if (!$this->ipcServer) {
            $this->ipcServer = new Server($this);
            $this->ipcServer->setIpcPath($this->wrapper->session);
        }
        $this->callCheckerLoop->start();
        $this->serializeLoop->start();
        $this->phoneConfigLoop->start();
        $this->configLoop->start();
        $this->checkTosLoop->start();
        $this->rpcLoop->start();
        $this->ipcServer->start();
    }
    /**
     * Stop all internal loops.
     *
     * @return void
     */
    private function stopLoops()
    {
        if ($this->callCheckerLoop) {
            $this->callCheckerLoop->signal(true);
            $this->callCheckerLoop = null;
        }
        if ($this->serializeLoop) {
            $this->serializeLoop->signal(true);
            $this->serializeLoop = null;
        }
        if ($this->phoneConfigLoop) {
            $this->phoneConfigLoop->signal(true);
            $this->phoneConfigLoop = null;
        }
        if ($this->configLoop) {
            $this->configLoop->signal(true);
            $this->configLoop = null;
        }
        if ($this->checkTosLoop) {
            $this->checkTosLoop->signal(true);
            $this->checkTosLoop = null;
        }
        if ($this->rpcLoop) {
            $this->rpcLoop->signal(true);
            $this->rpcLoop = null;
        }
        if ($this->ipcServer) {
            $this->ipcServer->signal(null);
            $this->ipcServer = null;
        }
    }
    /**
     * Report RPC errors.
     *
     * @internal
     *
     * @return \Generator
     */
    public function rpcReport(): \Generator
    {
        $toReport = RPCErrorException::$toReport;
        RPCErrorException::$toReport = [];
        foreach ($toReport as [$method, $code, $error, $time]) {
            try {
                $res = \json_decode(yield from $this->fileGetContents('https://rpc.pwrtelegram.xyz/?method='.$method.'&code='.$code.'&error='.$error.'&t='.$time), true);
                if (isset($res['ok']) && $res['ok'] && isset($res['result'])) {
                    $description = $res['result'];
                    RPCErrorException::$descriptions[$error] = $description;
                    RPCErrorException::$errorMethodMap[$code][$method][$error] = $error;
                }
            } catch (\Throwable $e) {
            }
        }
    }
    /**
     * Clean up properties from previous versions of MadelineProto.
     *
     * @internal
     *
     * @return void
     */
    private function cleanupProperties()
    {
        if (!$this->channels_state instanceof CombinedUpdatesState) {
            $this->channels_state = new CombinedUpdatesState($this->channels_state);
        }
        if (isset($this->updates_state)) {
            if (!$this->updates_state instanceof UpdatesState) {
                $this->updates_state = new UpdatesState($this->updates_state);
            }
            $this->channels_state->__construct([UpdateLoop::GENERIC => $this->updates_state]);
            unset($this->updates_state);
        }
        if (!isset($this->datacenter)) {
            $this->datacenter ??= new DataCenter($this, $this->dcList, $this->settings->getConnection());
        }
        if (!isset($this->referenceDatabase)) {
            $this->referenceDatabase = new ReferenceDatabase($this);
            yield from $this->referenceDatabase->init();
        } else {
            yield from $this->referenceDatabase->init();
        }
        if (!isset($this->minDatabase)) {
            $this->minDatabase = new MinDatabase($this);
            yield from $this->minDatabase->init();
        } else {
            yield from $this->minDatabase->init();
        }
        if (!isset($this->TL)) {
            $this->TL = new TL($this);
            $this->logger->logger(Lang::$current_lang['TL_translation'], Logger::ULTRA_VERBOSE);
            $callbacks = [$this, $this->referenceDatabase];
            if (!($this->authorization['user']['bot'] ?? false)) {
                $callbacks[] = $this->minDatabase;
            }
            $this->TL->init($this->settings->getSchema(), $callbacks);
        }

        yield from $this->initDb($this);
        yield from $this->fillUsernamesCache();
    }

    /**
     * Upgrade MadelineProto instance.
     *
     * @return \Generator
     * @throws Exception
     * @throws RPCErrorException
     * @throws \Throwable
     */
    private function upgradeMadelineProto(): \Generator
    {
        if (!isset($this->snitch)) {
            $this->snitch = new Snitch;
        }
        $this->logger->logger(Lang::$current_lang['serialization_ofd'], Logger::WARNING);
        foreach ($this->datacenter->getDataCenterConnections() as $dc_id => $socket) {
            if ($this->authorized === self::LOGGED_IN && \strpos($dc_id, '_') === false && $socket->hasPermAuthKey() && $socket->hasTempAuthKey()) {
                $socket->bind();
                $socket->authorized(true);
            }
        }
        $this->settings->setSchema(new TLSchema);

        yield from $this->initDb($this);

        if (!isset($this->secret_chats)) {
            $this->secret_chats = [];
        }
        $iterator = $this->full_chats->getIterator();
        while (yield $iterator->advance()) {
            [$id, $full] = $iterator->getCurrent();
            if (isset($full['full'], $full['last_update'])) {
                $this->full_chats[$id] = ['full' => $full['full'], 'last_update' => $full['last_update']];
            }
        }

        foreach ($this->secret_chats as $key => &$chat) {
            if (!\is_array($chat)) {
                unset($this->secret_chats[$key]);
                continue;
            }
            if ($chat['layer'] >= 73) {
                $chat['mtproto'] = 2;
            } else {
                $chat['mtproto'] = 1;
            }
        }
        unset($chat);

        $this->resetMTProtoSession(true, true);
        $this->config = ['expires' => -1];
        $this->dh_config = ['version' => 0];
        yield from $this->__construct_async($this->settings);
        foreach ($this->secret_chats as $chat => $data) {
            try {
                if (isset($this->secret_chats[$chat]) && $this->secret_chats[$chat]['InputEncryptedChat'] !== null) {
                    yield from $this->notifyLayer($chat);
                }
            } catch (\danog\MadelineProto\RPCErrorException $e) {
            }
        }
    }
    /**
     * Post-deserialization initialization function.
     *
     * @param Settings|SettingsEmpty $settings New settings
     * @param APIWrapper             $wrapper  API wrapper
     *
     * @internal
     *
     * @return \Generator
     */
    public function wakeup(SettingsAbstract $settings, APIWrapper $wrapper): \Generator
    {
        // Set API wrapper
        $this->wrapper = $wrapper;
        // BC stuff
        if ($this->authorized === true) {
            $this->authorized = self::LOGGED_IN;
        }
        // Convert old array settings to new settings object
        if (\is_array($this->settings)) {
            if (($this->settings['updates']['callback'] ?? '') === 'getUpdatesUpdateHandler') {
                $this->settings['updates']['callback'] = [$this, 'getUpdatesUpdateHandler'];
            }
            if (\is_callable($this->settings['updates']['callback'] ?? null)) {
                $this->updateHandler = $this->settings['updates']['callback'];
            }

            $this->dcList = $this->settings['connection'] ?? $this->dcList;
        }
        $this->settings = Settings::parseFromLegacy($this->settings);
        // Clean up phone call array
        foreach ($this->calls as $id => $controller) {
            if (!\is_object($controller)) {
                unset($this->calls[$id]);
            } elseif ($controller->getCallState() === VoIP::CALL_STATE_ENDED) {
                $controller->setMadeline($this);
                $controller->discard();
            } else {
                $controller->setMadeline($this);
            }
        }

        $this->forceInit(false);
        $this->setInitPromise($this->wakeupAsync($settings));

        return $this->initAsynchronously();
    }
    /**
     * Async wakeup function.
     *
     * @param Settings|SettingsEmpty $settings New settings
     *
     * @return \Generator
     */
    private function wakeupAsync(SettingsAbstract $settings): \Generator
    {
        // Setup one-time stuffs
        Magic::classExists();
        $this->settings->getConnection()->init();
        // Setup logger
        $this->setupLogger();
        // Setup language
        Lang::$current_lang =& Lang::$lang['en'];
        if (Lang::$lang[$this->settings->getAppInfo()->getLangCode()] ?? false) {
            Lang::$current_lang =& Lang::$lang[$this->settings->getAppInfo()->getLangCode()];
        }
        // Reset MTProto session (not related to user session)
        $this->resetMTProtoSession();
        // Update settings from constructor
        yield from $this->updateSettings($settings, false);
        // Session update process for BC
        $forceDialogs = false;
        if (!isset($this->v)
            || $this->v !== self::V
            || $this->settings->getSchema()->needsUpgrade()) {
            yield from $this->upgradeMadelineProto();
            $forceDialogs = true;
        }
        // Cleanup old properties, init new stuffs
        yield from $this->cleanupProperties();
        // Update TL callbacks
        $callbacks = [$this, $this->referenceDatabase];
        if (!($this->authorization['user']['bot'] ?? false)) {
            $callbacks[] = $this->minDatabase;
        }
        $this->TL->updateCallbacks($callbacks);
        // Connect to all DCs, start internal loops
        yield from $this->connectToAllDcs();
        $this->startLoops();
        if (yield from $this->fullGetSelf()) {
            $this->authorized = self::LOGGED_IN;
            $this->setupLogger();
            yield from $this->getCdnConfig($this->datacenter->curdc);
            yield from $this->initAuthorization();
        }
        // onStart event handler
        if ($this->event_handler && \class_exists($this->event_handler) && \is_subclass_of($this->event_handler, EventHandler::class)) {
            yield from $this->setEventHandler($this->event_handler);
        }
        $this->startUpdateSystem(true);
        if ($this->authorized === self::LOGGED_IN && !$this->authorization['user']['bot'] && $this->settings->getPeer()->getCacheAllPeersOnStartup()) {
            yield from $this->getDialogs($forceDialogs);
        }
        if ($this->authorized === self::LOGGED_IN) {
            $this->logger->logger(Lang::$current_lang['getupdates_deserialization'], Logger::NOTICE);
            yield $this->updaters[UpdateLoop::GENERIC]->resume();
        }
        $this->updaters[UpdateLoop::GENERIC]->start();

        GarbageCollector::start();
    }
    /**
     * Unreference instance, allowing destruction.
     *
     * @internal
     *
     * @return void
     */
    public function unreference(): void
    {
        $this->logger->logger("Will unreference instance");
        $this->stopLoops();
        if (isset($this->seqUpdater)) {
            $this->seqUpdater->signal(true);
        }
        $channelIds = [];
        foreach ($this->channels_state->get() as $state) {
            $channelIds[] = $state->getChannel();
        }
        \sort($channelIds);
        foreach ($channelIds as $channelId) {
            if (isset($this->feeders[$channelId])) {
                $this->feeders[$channelId]->signal(true);
            }
            if (isset($this->updaters[$channelId])) {
                $this->updaters[$channelId]->signal(true);
            }
        }
        foreach ($this->datacenter->getDataCenterConnections() as $datacenter) {
            $datacenter->disconnect();
        }
    }
    /**
     * Destructor.
     */
    public function __destruct()
    {
        $this->logger('Shutting down MadelineProto (MTProto)');
        $this->unreference();
        $this->logger("Successfully destroyed MadelineProto");
    }
    /**
     * Restart IPC server instance.
     *
     * @internal
     */
    public function restartIpcServer(): Promise
    {
        return new Success(); // Can only be called from client
    }
    /**
     * Whether we're an IPC client instance.
     *
     * @return boolean
     */
    public function isIpc(): bool
    {
        return false;
    }
    /**
     * Parse, update and store settings.
     *
     * @param Settings|SettingsEmpty $settings Settings
     * @param bool                   $reinit   Whether to reinit the instance
     *
     * @return \Generator
     */
    public function updateSettings(SettingsAbstract $settings, bool $reinit = true): \Generator
    {
        if ($settings instanceof SettingsEmpty) {
            if (!isset($this->settings)) {
                $this->settings = new Settings;
            } else {
                return;
            }
        } else {
            if (!isset($this->settings)) {
                if ($settings instanceof Settings) {
                    $this->settings = $settings;
                } else {
                    $this->settings = new Settings;
                    $this->settings->merge($settings);
                }
            } else {
                $this->settings->merge($settings);
            }
        }
        if (!$this->settings->getAppInfo()->hasApiInfo()) {
            throw new \danog\MadelineProto\Exception(Lang::$current_lang['api_not_set'], 0, null, 'MadelineProto', 1);
        }

        // Setup logger
        $this->setupLogger();

        if ($reinit) {
            yield from $this->__construct_async($this->settings);
        }
    }
    /**
     * Return current settings.
     *
     * @return Settings
     */
    public function getSettings(): Settings
    {
        return $this->settings;
    }
    /**
     * Setup logger.
     *
     * @return void
     */
    public function setupLogger(): void
    {
        $this->logger = new Logger(
            $this->settings->getLogger(),
            $this->authorization['user']['username'] ?? $this->authorization['user']['id'] ?? ''
        );
    }
    /**
     * Reset all MTProto sessions.
     *
     * @param boolean $de       Whether to reset the session ID
     * @param boolean $auth_key Whether to reset the auth key
     *
     * @internal
     *
     * @return void
     */
    public function resetMTProtoSession(bool $de = true, bool $auth_key = false): void
    {
        if (!\is_object($this->datacenter)) {
            throw new Exception(Lang::$current_lang['session_corrupted']);
        }
        foreach ($this->datacenter->getDataCenterConnections() as $id => $socket) {
            if ($de) {
                $socket->resetSession();
            }
            if ($auth_key) {
                $socket->setAuthKey(null);
            }
        }
    }
    /**
     * Check if connected to datacenter using HTTP.
     *
     * @param string $datacenter DC ID
     *
     * @internal
     *
     * @return boolean
     */
    public function isHttp(string $datacenter): bool
    {
        return $this->datacenter->isHttp($datacenter);
    }
    /**
     * Checks whether all datacenters are authorized.
     *
     * @return boolean
     */
    public function hasAllAuth(): bool
    {
        if ($this->isInitingAuthorization()) {
            return false;
        }
        foreach ($this->datacenter->getDataCenterConnections() as $dc) {
            if ((!$dc->isAuthorized() || !$dc->hasTempAuthKey()) && !$dc->isCDN()) {
                return false;
            }
        }
        return true;
    }
    /**
     * Whether we're initing authorization.
     *
     * @internal
     *
     * @return boolean
     */
    public function isInitingAuthorization()
    {
        return $this->initing_authorization;
    }
    /**
     * Connects to all datacenters and if necessary creates authorization keys, binds them and writes client info.
     *
     * @param boolean $reconnectAll Whether to reconnect to all DCs
     *
     * @return \Generator
     */
    public function connectToAllDcs(bool $reconnectAll = true): \Generator
    {
        $this->channels_state->get(FeedLoop::GENERIC);
        foreach ($this->channels_state->get() as $state) {
            $channelId = $state->getChannel();
            if (!isset($this->feeders[$channelId])) {
                $this->feeders[$channelId] = new FeedLoop($this, $channelId);
            }
            if (!isset($this->updaters[$channelId])) {
                $this->updaters[$channelId] = new UpdateLoop($this, $channelId);
            }
        }
        if (!isset($this->seqUpdater)) {
            $this->seqUpdater = new SeqLoop($this);
        }
        $this->datacenter->__construct($this, $this->dcList, $this->settings->getConnection(), $reconnectAll);
        $dcs = [];
        foreach ($this->datacenter->getDcs() as $new_dc) {
            $dcs[] = $this->datacenter->dcConnect($new_dc);
        }
        yield \danog\MadelineProto\Tools::all($dcs);
        yield from $this->initAuthorization();
        yield from $this->parseConfig();
        $dcs = [];
        foreach ($this->datacenter->getDcs(false) as $new_dc) {
            $dcs[] = $this->datacenter->dcConnect($new_dc);
        }
        yield \danog\MadelineProto\Tools::all($dcs);
        yield from $this->initAuthorization();
        yield from $this->parseConfig();
        yield from $this->getPhoneConfig();
    }
    /**
     * Clean up MadelineProto session after logout.
     *
     * @internal
     *
     * @return \Generator<void>
     */
    public function resetSession(): \Generator
    {
        if (isset($this->seqUpdater)) {
            $this->seqUpdater->signal(true);
            unset($this->seqUpdater);
        }
        $channelIds = [];
        foreach ($this->channels_state->get() as $state) {
            $channelIds[] = $state->getChannel();
        }
        \sort($channelIds);
        foreach ($channelIds as $channelId) {
            if (isset($this->feeders[$channelId])) {
                $this->feeders[$channelId]->signal(true);
                unset($this->feeders[$channelId]);
            }
            if (isset($this->updaters[$channelId])) {
                $this->updaters[$channelId]->signal(true);
                unset($this->updaters[$channelId]);
            }
        }
        foreach ($this->datacenter->getDataCenterConnections() as $socket) {
            $socket->authorized(false);
        }
        $this->channels_state = new CombinedUpdatesState();
        $this->got_state = false;
        $this->msg_ids = [];
        $this->authorized = self::NOT_LOGGED_IN;
        $this->authorized_dc = -1;
        $this->authorization = null;
        $this->updates = [];
        $this->secret_chats = [];

        yield from $this->initDb($this, true);

        $this->tos = ['expires' => 0, 'accepted' => true];
        $this->dialog_params = ['_' => 'MadelineProto.dialogParams', 'limit' => 0, 'offset_date' => 0, 'offset_id' => 0, 'offset_peer' => ['_' => 'inputPeerEmpty'], 'count' => 0];

        $this->referenceDatabase = new ReferenceDatabase($this);
        yield from $this->referenceDatabase->init();

        $this->minDatabase = new MinDatabase($this);
        yield from $this->minDatabase->init();
    }
    /**
     * Reset the update state and fetch all updates from the beginning.
     *
     * @return void
     */
    public function resetUpdateState(): void
    {
        if (isset($this->seqUpdater)) {
            $this->seqUpdater->signal(true);
        }
        $channelIds = [];
        $newStates = [];
        foreach ($this->channels_state->get() as $state) {
            $channelIds[] = $state->getChannel();
            $channelId = $state->getChannel();
            $pts = $state->pts();
            $pts = $channelId ? \max(1, $pts - 1000000) : ($pts > 4000000 ? $pts - 1000000 : \max(1, $pts - 1000000));
            $newStates[$channelId] = new UpdatesState(['pts' => $pts], $channelId);
        }
        \sort($channelIds);
        foreach ($channelIds as $channelId) {
            if (isset($this->feeders[$channelId])) {
                $this->feeders[$channelId]->signal(true);
            }
            if (isset($this->updaters[$channelId])) {
                $this->updaters[$channelId]->signal(true);
            }
        }
        $this->channels_state->__construct($newStates);
        $this->startUpdateSystem();
    }
    /**
     * Start the update system.
     *
     * @param boolean $anyway Force start update system?
     *
     * @internal
     *
     * @return void
     */
    public function startUpdateSystem($anyway = false): void
    {
        if (!$this->inited() && !$anyway) {
            $this->logger("Not starting update system");
            return;
        }
        $this->logger("Starting update system");
        if (!isset($this->seqUpdater)) {
            $this->seqUpdater = new SeqLoop($this);
        }
        $this->channels_state->get(FeedLoop::GENERIC);
        $channelIds = [];
        foreach ($this->channels_state->get() as $state) {
            $channelIds[] = $state->getChannel();
        }
        \sort($channelIds);
        foreach ($channelIds as $channelId) {
            if (!isset($this->feeders[$channelId])) {
                $this->feeders[$channelId] = new FeedLoop($this, $channelId);
            }
            if (!isset($this->updaters[$channelId])) {
                $this->updaters[$channelId] = new UpdateLoop($this, $channelId);
            }
            if ($this->feeders[$channelId]->start() && isset($this->feeders[$channelId])) {
                $this->feeders[$channelId]->resume();
            }
            if ($this->updaters[$channelId]->start() && isset($this->updaters[$channelId])) {
                $this->updaters[$channelId]->resume();
            }
        }
        foreach ($this->datacenter->getDataCenterConnections() as $datacenter) {
            $datacenter->flush();
        }
        if ($this->seqUpdater->start()) {
            $this->seqUpdater->resume();
        }
    }
    /**
     * Store shared phone config.
     *
     * @param mixed $watcherId Watcher ID
     *
     * @internal
     *
     * @return \Generator<void>
     */
    public function getPhoneConfig($watcherId = null): \Generator
    {
        if ($this->authorized === self::LOGGED_IN
            && \class_exists(VoIPServerConfigInternal::class)
            && !$this->authorization['user']['bot']
            && $this->datacenter->getDataCenterConnection($this->settings->getDefaultDc())->hasTempAuthKey()) {
            $this->logger->logger('Fetching phone config...');
            VoIPServerConfig::updateDefault(yield from $this->methodCallAsyncRead('phone.getCallConfig', [], $this->settings->getDefaultDcParams()));
        } else {
            $this->logger->logger('Not fetching phone config');
        }
    }
    /**
     * Store RSA keys for CDN datacenters.
     *
     * @param string $datacenter DC ID
     *
     * @return \Generator
     */
    public function getCdnConfig(string $datacenter): \Generator
    {
        try {
            foreach ((yield from $this->methodCallAsyncRead('help.getCdnConfig', [], ['datacenter' => $datacenter]))['public_keys'] as $curkey) {
                $curkey = (yield from (new RSA())->load($this->TL, $curkey['public_key']));
                $this->cdn_rsa_keys[$curkey->fp] = $curkey;
            }
        } catch (\danog\MadelineProto\TL\Exception $e) {
            $this->logger->logger($e->getMessage(), \danog\MadelineProto\Logger::FATAL_ERROR);
        }
    }
    /**
     * Get cached server-side config.
     *
     * @return array
     */
    public function getCachedConfig(): array
    {
        return $this->config;
    }
    /**
     * Get cached (or eventually re-fetch) server-side config.
     *
     * @param array $config  Current config
     * @param array $options Options for method call
     *
     * @return \Generator
     */
    public function getConfig(array $config = [], array $options = []): \Generator
    {
        if ($this->config['expires'] > \time()) {
            return $this->config;
        }
        $this->config = empty($config) ? yield from $this->methodCallAsyncRead('help.getConfig', $config, $options ?: $this->settings->getDefaultDcParams()) : $config;
        yield from $this->parseConfig();
        $this->logger->logger(Lang::$current_lang['config_updated'], Logger::NOTICE);
        $this->logger->logger($this->config, Logger::NOTICE);
        return $this->config;
    }
    /**
     * Parse cached config.
     *
     * @return \Generator
     */
    private function parseConfig(): \Generator
    {
        if (isset($this->config['dc_options'])) {
            $options = $this->config['dc_options'];
            unset($this->config['dc_options']);
            yield from $this->parseDcOptions($options);
        }
    }
    /**
     * Parse DC options from config.
     *
     * @param array $dc_options DC options
     *
     * @return \Generator
     */
    private function parseDcOptions(array $dc_options): \Generator
    {
        $previous = $this->dcList;
        foreach ($dc_options as $dc) {
            $test = $this->config['test_mode'] ? 'test' : 'main';
            $id = $dc['id'];
            if (isset($dc['static'])) {
                //$id .= $dc['static'] ? '_static' : '';
            }
            if (isset($dc['cdn'])) {
                $id .= $dc['cdn'] ? '_cdn' : '';
            }
            $id .= $dc['media_only'] ? '_media' : '';
            $ipv6 = $dc['ipv6'] ? 'ipv6' : 'ipv4';
            if (\is_numeric($id)) {
                $id = (int) $id;
            }
            unset($dc['cdn'], $dc['media_only'], $dc['id'], $dc['ipv6']);
            $this->dcList[$test][$ipv6][$id] = $dc;
        }
        $curdc = $this->datacenter->curdc;
        if ($previous !== $this->dcList && (!$this->datacenter->has($curdc) || $this->datacenter->getDataCenterConnection($curdc)->byIPAddress())) {
            $this->logger->logger('Got new DC options, reconnecting');
            yield from $this->connectToAllDcs(false);
        }
        $this->datacenter->curdc = $curdc;
    }
    /**
     * Get info about the logged-in user, cached.
     *
     * @return array|bool
     */
    public function getSelf()
    {
        return $this->authorization['user'] ?? false;
    }
    /**
     * Get info about the logged-in user, not cached.
     *
     * @return \Generator<array|bool>
     */
    public function fullGetSelf(): \Generator
    {
        try {
            $this->authorization = ['user' => (yield from $this->methodCallAsyncRead('users.getUsers', ['id' => [['_' => 'inputUserSelf']]], ['datacenter' => $this->datacenter->curdc]))[0]];
        } catch (RPCErrorException $e) {
            $this->logger->logger($e->getMessage());
            return false;
        }
        return $this->authorization['user'];
    }
    /**
     * Get authorization info.
     *
     * @return int
     */
    public function getAuthorization(): int
    {
        return $this->authorized;
    }
    /**
     * IDs of peers where to report errors.
     *
     * @var int[]
     */
    private $reportDest = [];
    /**
     * Check if has report peers.
     *
     * @return boolean
     */
    public function hasReportPeers(): bool
    {
        return (bool) $this->reportDest;
    }
    /**
     * Set peer(s) where to send errors occurred in the event loop.
     *
     * @param int|string $userOrId Username(s) or peer ID(s)
     *
     * @return \Generator
     */
    public function setReportPeers($userOrId): \Generator
    {
        if (!(\is_array($userOrId) && !isset($userOrId['_']) && !isset($userOrId['id']))) {
            $userOrId = [$userOrId];
        }
        foreach ($userOrId as $k => &$peer) {
            try {
                $peer = (yield from $this->getInfo($peer))['bot_api_id'];
            } catch (\Throwable $e) {
                unset($userOrId[$k]);
                $this->logger("Could not obtain info about report peer $peer: $e", Logger::FATAL_ERROR);
            }
        }
        $this->reportDest = $userOrId;
    }
    /**
     * Report an error to the previously set peer.
     *
     * @param string $message   Error to report
     * @param string $parseMode Parse mode
     *
     * @return \Generator
     */
    public function report(string $message, string $parseMode = ''): \Generator
    {
        if (!$this->reportDest) {
            return;
        }
        $file = null;
        if ($this->settings->getLogger()->getType() === Logger::FILE_LOGGER
            && $path = $this->settings->getLogger()->getExtra()) {
            StatCache::clear($path);
            if (!yield exists($path)) {
                $message = "!!! WARNING !!!\nThe logfile does not exist, please DO NOT delete the logfile to avoid errors in MadelineProto!\n\n$message";
            } elseif (!yield size($path)) {
                $message = "!!! WARNING !!!\nThe logfile is empty, please DO NOT delete the logfile to avoid errors in MadelineProto!\n\n$message";
            } else {
                $file = yield from $this->methodCallAsyncRead(
                    'messages.uploadMedia',
                    [
                        'peer' => $this->reportDest[0],
                        'media' => [
                            '_' => 'inputMediaUploadedDocument',
                            'file' => $path,
                            'attributes' => [
                                ['_' => 'documentAttributeFilename', 'file_name' => 'MadelineProto.log']
                            ]
                        ]
                    ]
                );
            }
        }
        $sent = true;
        foreach ($this->reportDest as $id) {
            try {
                yield from $this->methodCallAsyncRead('messages.sendMessage', ['peer' => $id, 'message' => $message, 'parse_mode' => $parseMode]);
                if ($file) {
                    yield from $this->methodCallAsyncRead('messages.sendMedia', ['peer' => $id, 'media' => $file]);
                }
                $sent &= true;
            } catch (\Throwable $e) {
                $sent &= false;
                $this->logger("While reporting to $id: $e", Logger::FATAL_ERROR);
            }
        }
        if ($sent && $file) {
            \ftruncate($this->logger->stdout->getResource(), 0);
            $this->logger->logger("Reported!");
        }
    }
    /**
     * Get full list of MTProto and API methods.
     *
     * @return array
     */
    public function getAllMethods(): array
    {
        $methods = [];
        foreach ($this->getTL()->getMethods()->by_id as $method) {
            $methods[] = $method['method'];
        }
        return \array_merge($methods, \get_class_methods(InternalDoc::class));
    }
    /**
     * Called right before serialization of method starts.
     *
     * Pass the method name
     *
     * @return array
     */
    public function getMethodCallbacks(): array
    {
        return [];
    }
    /**
     * Called right before serialization of method starts.
     *
     * Pass the method name
     *
     * @return array
     */
    public function getMethodBeforeCallbacks(): array
    {
        return [];
    }
    /**
     * Called right after deserialization of object, passing the final object.
     *
     * @return array
     */
    public function getConstructorCallbacks(): array
    {
        return \array_merge(
            \array_fill_keys(['chat', 'chatEmpty', 'chatForbidden', 'channel', 'channelEmpty', 'channelForbidden'], [[$this, 'addChat']]),
            \array_fill_keys(['user', 'userEmpty'], [[$this, 'addUser']]),
            ['help.support' => [[$this, 'addSupport']]]
        );
    }
    /**
     * Called right before deserialization of object.
     *
     * Pass only the constructor name
     *
     * @return array
     */
    public function getConstructorBeforeCallbacks(): array
    {
        return [];
    }
    /**
     * Called right before serialization of constructor.
     *
     * Passed the object, will return a modified version.
     *
     * @return array
     */
    public function getConstructorSerializeCallbacks(): array
    {
        return [];
    }
    /**
     * Called if objects of the specified type cannot be serialized.
     *
     * Passed the unserializable object,
     * will try to convert it to an object of the proper type.
     *
     * @return array
     */
    public function getTypeMismatchCallbacks(): array
    {
        return \array_merge(\array_fill_keys(['User', 'InputUser', 'Chat', 'InputChannel', 'Peer', 'InputPeer', 'InputDialogPeer', 'InputNotifyPeer'], [$this, 'getInfo']), \array_fill_keys(['InputMedia', 'InputDocument', 'InputPhoto'], [$this, 'getFileInfo']), \array_fill_keys(['InputFileLocation'], [$this, 'getDownloadInfo']));
    }
    /**
     * Get debug information for var_dump.
     *
     * @return array
     */
    public function __debugInfo(): array
    {
        $vars = \get_object_vars($this);
        unset($vars['full_chats'], $vars['chats'], $vars['referenceDatabase'], $vars['minDatabase'], $vars['TL']);
        return $vars;
    }
    const ALL_MIMES = ['webp' => [0 => 'image/webp'], 'png' => [0 => 'image/png', 1 => 'image/x-png'], 'bmp' => [0 => 'image/bmp', 1 => 'image/x-bmp', 2 => 'image/x-bitmap', 3 => 'image/x-xbitmap', 4 => 'image/x-win-bitmap', 5 => 'image/x-windows-bmp', 6 => 'image/ms-bmp', 7 => 'image/x-ms-bmp', 8 => 'application/bmp', 9 => 'application/x-bmp', 10 => 'application/x-win-bitmap'], 'gif' => [0 => 'image/gif'], 'jpeg' => [0 => 'image/jpeg', 1 => 'image/pjpeg'], 'xspf' => [0 => 'application/xspf+xml'], 'vlc' => [0 => 'application/videolan'], 'wmv' => [0 => 'video/x-ms-wmv', 1 => 'video/x-ms-asf'], 'au' => [0 => 'audio/x-au'], 'ac3' => [0 => 'audio/ac3'], 'flac' => [0 => 'audio/x-flac'], 'ogg' => [0 => 'audio/ogg', 1 => 'video/ogg', 2 => 'application/ogg'], 'kmz' => [0 => 'application/vnd.google-earth.kmz'], 'kml' => [0 => 'application/vnd.google-earth.kml+xml'], 'rtx' => [0 => 'text/richtext'], 'rtf' => [0 => 'text/rtf'], 'jar' => [0 => 'application/java-archive', 1 => 'application/x-java-application', 2 => 'application/x-jar'], 'zip' => [0 => 'application/x-zip', 1 => 'application/zip', 2 => 'application/x-zip-compressed', 3 => 'application/s-compressed', 4 => 'multipart/x-zip'], '7zip' => [0 => 'application/x-compressed'], 'xml' => [0 => 'application/xml', 1 => 'text/xml'], 'svg' => [0 => 'image/svg+xml'], '3g2' => [0 => 'video/3gpp2'], '3gp' => [0 => 'video/3gp', 1 => 'video/3gpp'], 'mp4' => [0 => 'video/mp4'], 'm4a' => [0 => 'audio/x-m4a'], 'f4v' => [0 => 'video/x-f4v'], 'flv' => [0 => 'video/x-flv'], 'webm' => [0 => 'video/webm'], 'aac' => [0 => 'audio/x-acc'], 'm4u' => [0 => 'application/vnd.mpegurl'], 'pdf' => [0 => 'application/pdf', 1 => 'application/octet-stream'], 'pptx' => [0 => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'], 'ppt' => [0 => 'application/powerpoint', 1 => 'application/vnd.ms-powerpoint', 2 => 'application/vnd.ms-office', 3 => 'application/msword'], 'docx' => [0 => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], 'xlsx' => [0 => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 1 => 'application/vnd.ms-excel'], 'xl' => [0 => 'application/excel'], 'xls' => [0 => 'application/msexcel', 1 => 'application/x-msexcel', 2 => 'application/x-ms-excel', 3 => 'application/x-excel', 4 => 'application/x-dos_ms_excel', 5 => 'application/xls', 6 => 'application/x-xls'], 'xsl' => [0 => 'text/xsl'], 'mpeg' => [0 => 'video/mpeg'], 'mov' => [0 => 'video/quicktime'], 'avi' => [0 => 'video/x-msvideo', 1 => 'video/msvideo', 2 => 'video/avi', 3 => 'application/x-troff-msvideo'], 'movie' => [0 => 'video/x-sgi-movie'], 'log' => [0 => 'text/x-log'], 'txt' => [0 => 'text/plain'], 'css' => [0 => 'text/css'], 'html' => [0 => 'text/html'], 'wav' => [0 => 'audio/x-wav', 1 => 'audio/wave', 2 => 'audio/wav'], 'xhtml' => [0 => 'application/xhtml+xml'], 'tar' => [0 => 'application/x-tar'], 'tgz' => [0 => 'application/x-gzip-compressed'], 'psd' => [0 => 'application/x-photoshop', 1 => 'image/vnd.adobe.photoshop'], 'exe' => [0 => 'application/x-msdownload'], 'js' => [0 => 'application/x-javascript'], 'mp3' => [0 => 'audio/mpeg', 1 => 'audio/mpg', 2 => 'audio/mpeg3', 3 => 'audio/mp3'], 'rar' => [0 => 'application/x-rar', 1 => 'application/rar', 2 => 'application/x-rar-compressed'], 'gzip' => [0 => 'application/x-gzip'], 'hqx' => [0 => 'application/mac-binhex40', 1 => 'application/mac-binhex', 2 => 'application/x-binhex40', 3 => 'application/x-mac-binhex40'], 'cpt' => [0 => 'application/mac-compactpro'], 'bin' => [0 => 'application/macbinary', 1 => 'application/mac-binary', 2 => 'application/x-binary', 3 => 'application/x-macbinary'], 'oda' => [0 => 'application/oda'], 'ai' => [0 => 'application/postscript'], 'smil' => [0 => 'application/smil'], 'mif' => [0 => 'application/vnd.mif'], 'wbxml' => [0 => 'application/wbxml'], 'wmlc' => [0 => 'application/wmlc'], 'dcr' => [0 => 'application/x-director'], 'dvi' => [0 => 'application/x-dvi'], 'gtar' => [0 => 'application/x-gtar'], 'php' => [0 => 'application/x-httpd-php', 1 => 'application/php', 2 => 'application/x-php', 3 => 'text/php', 4 => 'text/x-php', 5 => 'application/x-httpd-php-source'], 'swf' => [0 => 'application/x-shockwave-flash'], 'sit' => [0 => 'application/x-stuffit'], 'z' => [0 => 'application/x-compress'], 'mid' => [0 => 'audio/midi'], 'aif' => [0 => 'audio/x-aiff', 1 => 'audio/aiff'], 'ram' => [0 => 'audio/x-pn-realaudio'], 'rpm' => [0 => 'audio/x-pn-realaudio-plugin'], 'ra' => [0 => 'audio/x-realaudio'], 'rv' => [0 => 'video/vnd.rn-realvideo'], 'jp2' => [0 => 'image/jp2', 1 => 'video/mj2', 2 => 'image/jpx', 3 => 'image/jpm'], 'tiff' => [0 => 'image/tiff'], 'eml' => [0 => 'message/rfc822'], 'pem' => [0 => 'application/x-x509-user-cert', 1 => 'application/x-pem-file'], 'p10' => [0 => 'application/x-pkcs10', 1 => 'application/pkcs10'], 'p12' => [0 => 'application/x-pkcs12'], 'p7a' => [0 => 'application/x-pkcs7-signature'], 'p7c' => [0 => 'application/pkcs7-mime', 1 => 'application/x-pkcs7-mime'], 'p7r' => [0 => 'application/x-pkcs7-certreqresp'], 'p7s' => [0 => 'application/pkcs7-signature'], 'crt' => [0 => 'application/x-x509-ca-cert', 1 => 'application/pkix-cert'], 'crl' => [0 => 'application/pkix-crl', 1 => 'application/pkcs-crl'], 'pgp' => [0 => 'application/pgp'], 'gpg' => [0 => 'application/gpg-keys'], 'rsa' => [0 => 'application/x-pkcs7'], 'ics' => [0 => 'text/calendar'], 'zsh' => [0 => 'text/x-scriptzsh'], 'cdr' => [0 => 'application/cdr', 1 => 'application/coreldraw', 2 => 'application/x-cdr', 3 => 'application/x-coreldraw', 4 => 'image/cdr', 5 => 'image/x-cdr', 6 => 'zz-application/zz-winassoc-cdr'], 'wma' => [0 => 'audio/x-ms-wma'], 'vcf' => [0 => 'text/x-vcard'], 'srt' => [0 => 'text/srt'], 'vtt' => [0 => 'text/vtt'], 'ico' => [0 => 'image/x-icon', 1 => 'image/x-ico', 2 => 'image/vnd.microsoft.icon'], 'csv' => [0 => 'text/x-comma-separated-values', 1 => 'text/comma-separated-values', 2 => 'application/vnd.msexcel'], 'json' => [0 => 'application/json', 1 => 'text/json']];
}
