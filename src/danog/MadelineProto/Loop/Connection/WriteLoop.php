<?php

/**
 * Socket write loop.
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

namespace danog\MadelineProto\Loop\Connection;

use Amp\ByteStream\StreamException;
use Amp\Loop;
use danog\Loop\ResumableSignalLoop;
use danog\MadelineProto\Logger;
use danog\MadelineProto\MTProtoTools\Crypt;
use danog\MadelineProto\Tools;

/**
 * Socket write loop.
 *
 * @author Daniil Gentili <daniil@daniil.it>
 */
class WriteLoop extends ResumableSignalLoop
{
    const MAX_COUNT = 1020;
    const MAX_SIZE = 1 << 15;
    const MAX_IDS = 8192;

    use Common;
    /**
     * Main loop.
     *
     * @return \Generator
     */
    public function loop(): \Generator
    {
        $API = $this->API;
        $connection = $this->connection;
        $shared = $this->datacenterConnection;
        $datacenter = $this->datacenter;
        $please_wait = false;
        while (true) {
            while (empty($connection->pending_outgoing) || $please_wait) {
                if ($connection->shouldReconnect()) {
                    $API->logger->logger('Not writing because connection is old');
                    return;
                }
                $please_wait = false;
                $API->logger->logger("Waiting in {$this}", Logger::ULTRA_VERBOSE);
                if (yield $this->waitSignal($this->pause())) {
                    $API->logger->logger("Exiting {$this}", Logger::ULTRA_VERBOSE);
                    return;
                }
                $API->logger->logger("Done waiting in {$this}", Logger::ULTRA_VERBOSE);
                if ($connection->shouldReconnect()) {
                    $API->logger->logger('Not writing because connection is old');
                    return;
                }
            }
            $connection->writing(true);
            try {
                $please_wait = yield from $this->{$shared->hasTempAuthKey() ? 'encryptedWriteLoop' : 'unencryptedWriteLoop'}();
            } catch (StreamException $e) {
                if ($connection->shouldReconnect()) {
                    return;
                }
                Tools::callForkDefer((function () use ($API, $connection, $datacenter, $e): \Generator {
                    $API->logger->logger($e);
                    $API->logger->logger("Got nothing in the socket in DC {$datacenter}, reconnecting...", Logger::ERROR);
                    yield from $connection->reconnect();
                })());
                return;
            } finally {
                $connection->writing(false);
            }
            //$connection->waiter->resume();
        }
    }
    public function unencryptedWriteLoop(): \Generator
    {
        $API = $this->API;
        $datacenter = $this->datacenter;
        $connection = $this->connection;
        $shared = $this->datacenterConnection;
        while ($connection->pending_outgoing) {
            $skipped_all = true;
            foreach ($connection->pending_outgoing as $k => $message) {
                if ($shared->hasTempAuthKey()) {
                    return;
                }
                if (!$message['unencrypted']) {
                    continue;
                }
                $skipped_all = false;
                $API->logger->logger("Sending {$message['_']} as unencrypted message to DC {$datacenter}", \danog\MadelineProto\Logger::ULTRA_VERBOSE);
                $message_id = isset($message['msg_id']) ? $message['msg_id'] : $connection->msgIdHandler->generateMessageId();
                $length = \strlen($message['serialized_body']);
                $pad_length = -$length & 15;
                $pad_length += 16 * \danog\MadelineProto\Tools::randomInt($modulus = 16);
                $pad = \danog\MadelineProto\Tools::random($pad_length);
                $buffer = yield $connection->stream->getWriteBuffer(8 + 8 + 4 + $pad_length + $length);
                yield $buffer->bufferWrite("\0\0\0\0\0\0\0\0".$message_id.\danog\MadelineProto\Tools::packUnsignedInt($length).$message['serialized_body'].$pad);
                //var_dump("plain ".bin2hex($message_id));
                $connection->httpSent();
                $connection->outgoing_messages[$message_id] = $message;
                $connection->outgoing_messages[$message_id]['sent'] = \time();
                $connection->outgoing_messages[$message_id]['tries'] = 0;
                $connection->outgoing_messages[$message_id]['unencrypted'] = true;
                $connection->new_outgoing[$message_id] = $message_id;
                unset($connection->pending_outgoing[$k]);
                $API->logger->logger("Sent {$message['_']} as unencrypted message to DC {$datacenter}!", \danog\MadelineProto\Logger::ULTRA_VERBOSE);
                $message['send_promise']->resolve(isset($message['promise']) ? $message['promise'] : true);
                unset($message['send_promise']);
            }
            if ($skipped_all) {
                return true;
            }
        }
    }
    public function encryptedWriteLoop(): \Generator
    {
        $API = $this->API;
        $datacenter = $this->datacenter;
        $connection = $this->connection;
        $shared = $this->datacenterConnection;
        do {
            if (!$shared->hasTempAuthKey()) {
                return;
            }
            if ($shared->isHttp() && empty($connection->pending_outgoing)) {
                return;
            }

            \ksort($connection->pending_outgoing);

            $messages = [];
            $keys = [];

            $total_length = 0;
            $count = 0;
            $skipped = false;
            $inited = false;

            $has_seq = false;

            $has_state = false;
            $has_resend = false;
            $has_http_wait = false;
            foreach ($connection->pending_outgoing as $k => $message) {
                if ($message['unencrypted']) {
                    continue;
                }
                if (isset($message['container'])) {
                    unset($connection->pending_outgoing[$k]);
                    continue;
                }
                if ($shared->getGenericSettings()->getAuth()->getPfs() && !$shared->isBound() && !$connection->isCDN() && !\in_array($message['_'], ['http_wait', 'auth.bindTempAuthKey']) && $message['method']) {
                    $API->logger->logger("Skipping {$message['_']} due to unbound keys in DC {$datacenter}");
                    $skipped = true;
                    continue;
                }
                if ($message['_'] === 'http_wait') {
                    $has_http_wait = true;
                }
                if ($message['_'] === 'msgs_state_req') {
                    if ($has_state) {
                        $API->logger->logger("Already have a state request queued for the current container in DC {$datacenter}");
                        continue;
                    }
                    $has_state = true;
                }
                if ($message['_'] === 'msg_resend_req') {
                    if ($has_resend) {
                        $API->logger->logger("Already have a resend request queued for the current container in DC {$datacenter}");
                        continue;
                    }
                    $has_resend = true;
                }

                $body_length = \strlen($message['serialized_body']);
                $actual_length = $body_length + 32;
                if ($total_length && $total_length + $actual_length > 32760 || $count >= 1020) {
                    $API->logger->logger('Length overflow, postponing part of payload', \danog\MadelineProto\Logger::ULTRA_VERBOSE);
                    break;
                }
                if (isset($message['seqno'])) {
                    $has_seq = true;
                }

                $message_id = $message['msg_id'] ?? $connection->msgIdHandler->generateMessageId();
                $API->logger->logger("Sending {$message['_']} as encrypted message to DC {$datacenter}", \danog\MadelineProto\Logger::ULTRA_VERBOSE);
                $MTmessage = [
                    '_' => 'MTmessage',
                    'msg_id' => $message_id,
                    'body' => $message['serialized_body'],
                    'seqno' => $message['seqno'] ?? $connection->generateOutSeqNo($message['contentRelated'])
                ];
                if (isset($message['method']) && $message['method'] && $message['_'] !== 'http_wait') {
                    if (!$shared->getTempAuthKey()->isInited() && $message['_'] !== 'auth.bindTempAuthKey' && !$inited) {
                        $inited = true;
                        $API->logger->logger(\sprintf(\danog\MadelineProto\Lang::$current_lang['write_client_info'], $message['_']), \danog\MadelineProto\Logger::NOTICE);
                        $MTmessage['body'] = (yield from $API->getTL()->serializeMethod('invokeWithLayer', ['layer' => $API->settings->getSchema()->getLayer(), 'query' => yield from $API->getTL()->serializeMethod('initConnection', ['api_id' => $API->settings->getAppInfo()->getApiId(), 'api_hash' => $API->settings->getAppInfo()->getApiHash(), 'device_model' => !$connection->isCDN() ? $API->settings->getAppInfo()->getDeviceModel() : 'n/a', 'system_version' => !$connection->isCDN() ? $API->settings->getAppInfo()->getSystemVersion() : 'n/a', 'app_version' => $API->settings->getAppInfo()->getAppVersion(), 'system_lang_code' => $API->settings->getAppInfo()->getLangCode(), 'lang_code' => $API->settings->getAppInfo()->getLangCode(), 'lang_pack' => $API->settings->getAppInfo()->getLangPack(), 'proxy' => $connection->getCtx()->getInputClientProxy(), 'query' => $MTmessage['body']])]));
                    } else {
                        if (isset($message['queue'])) {
                            if (!isset($connection->call_queue[$message['queue']])) {
                                $connection->call_queue[$message['queue']] = [];
                            }
                            $MTmessage['body'] = (yield from $API->getTL()->serializeMethod('invokeAfterMsgs', ['msg_ids' => $connection->call_queue[$message['queue']], 'query' => $MTmessage['body']]));
                            $connection->call_queue[$message['queue']][$message_id] = $message_id;
                            if (\count($connection->call_queue[$message['queue']]) > $API->settings->getRpc()->getLimitCallQueue()) {
                                \reset($connection->call_queue[$message['queue']]);
                                $key = \key($connection->call_queue[$message['queue']]);
                                unset($connection->call_queue[$message['queue']][$key]);
                            }
                        }
                        // TODO
                        /*
                        if ($API->settings['requests']['gzip_encode_if_gt'] !== -1 && ($l = strlen($MTmessage['body'])) > $API->settings['requests']['gzip_encode_if_gt']) {
                            if (($g = strlen($gzipped = gzencode($MTmessage['body']))) < $l) {
                                $MTmessage['body'] = yield $API->getTL()->serializeObject(['type' => ''], ['_' => 'gzip_packed', 'packed_data' => $gzipped], 'gzipped data');
                                $API->logger->logger('Using GZIP compression for ' . $message['_'] . ', saved ' . ($l - $g) . ' bytes of data, reduced call size by ' . $g * 100 / $l . '%', \danog\MadelineProto\Logger::ULTRA_VERBOSE);
                            }
                            unset($gzipped);
                        }*/
                    }
                }
                $body_length = \strlen($MTmessage['body']);
                $actual_length = $body_length + 32;
                if ($total_length && $total_length + $actual_length > 32760) {
                    $API->logger->logger('Length overflow, postponing part of payload', \danog\MadelineProto\Logger::ULTRA_VERBOSE);
                    break;
                }
                $count++;
                $total_length += $actual_length;
                $MTmessage['bytes'] = $body_length;
                $messages[] = $MTmessage;
                $keys[$k] = $message_id;

                $connection->pending_outgoing[$k]['seqno'] = $MTmessage['seqno'];
                $connection->pending_outgoing[$k]['msg_id'] = $MTmessage['msg_id'];
            }
            $MTmessage = null;

            $acks = \array_slice($connection->ack_queue, 0, self::MAX_COUNT);
            if ($ackCount = \count($acks)) {
                $API->logger->logger("Adding msgs_ack", Logger::ULTRA_VERBOSE);

                $body = yield from $this->API->getTL()->serializeObject(['type' => ''], ['_' => 'msgs_ack', 'msg_ids' => $acks], 'msgs_ack');
                $messages []= [
                    '_' => 'MTmessage',
                    'msg_id' => $connection->msgIdHandler->generateMessageId(),
                    'body' => $body,
                    'seqno' => $connection->generateOutSeqNo(false),
                    'bytes' => \strlen($body)
                ];
                $count++;
                unset($acks, $body);
            }
            if ($shared->isHttp() && !$has_http_wait) {
                $API->logger->logger("Adding http_wait", Logger::ULTRA_VERBOSE);
                $body = yield from $this->API->getTL()->serializeObject(['type' => ''], ['_' => 'http_wait', 'max_wait' => 30000, 'wait_after' => 0, 'max_delay' => 0], 'http_wait');
                $messages []= [
                    '_' => 'MTmessage',
                    'msg_id' => $connection->msgIdHandler->generateMessageId(),
                    'body' => $body,
                    'seqno' => $connection->generateOutSeqNo(true),
                    'bytes' => \strlen($body)
                ];
                $count++;
                unset($body);
            }

            if ($count > 1 || $has_seq) {
                $API->logger->logger("Wrapping in msg_container ({$count} messages of total size {$total_length}) as encrypted message for DC {$datacenter}", \danog\MadelineProto\Logger::ULTRA_VERBOSE);
                $message_id = $connection->msgIdHandler->generateMessageId();
                $connection->pending_outgoing[$connection->pending_outgoing_key] = ['_' => 'msg_container', 'container' => \array_values($keys), 'contentRelated' => false, 'method' => false, 'unencrypted' => false];
                $keys[$connection->pending_outgoing_key++] = $message_id;
                $message_data = (yield from $API->getTL()->serializeObject(['type' => ''], ['_' => 'msg_container', 'messages' => $messages], 'container'));
                $message_data_length = \strlen($message_data);
                $seq_no = $connection->generateOutSeqNo(false);
            } elseif ($count) {
                $message = $messages[0];
                $message_data = $message['body'];
                $message_data_length = $message['bytes'];
                $message_id = $message['msg_id'];
                $seq_no = $message['seqno'];
            } else {
                $API->logger->logger("NO MESSAGE SENT in DC {$datacenter}", \danog\MadelineProto\Logger::WARNING);
                return true;
            }
            unset($messages);
            $plaintext = $shared->getTempAuthKey()->getServerSalt().$connection->session_id.$message_id.\pack('VV', $seq_no, $message_data_length).$message_data;
            $padding = \danog\MadelineProto\Tools::posmod(-\strlen($plaintext), 16);
            if ($padding < 12) {
                $padding += 16;
            }
            $padding = \danog\MadelineProto\Tools::random($padding);
            $message_key = \substr(\hash('sha256', \substr($shared->getTempAuthKey()->getAuthKey(), 88, 32).$plaintext.$padding, true), 8, 16);
            list($aes_key, $aes_iv) = Crypt::aesCalculate($message_key, $shared->getTempAuthKey()->getAuthKey());
            $message = $shared->getTempAuthKey()->getID().$message_key.Crypt::igeEncrypt($plaintext.$padding, $aes_key, $aes_iv);
            $buffer = yield $connection->stream->getWriteBuffer(\strlen($message));
            yield $buffer->bufferWrite($message);
            $connection->httpSent();
            $API->logger->logger("Sent encrypted payload to DC {$datacenter}", \danog\MadelineProto\Logger::ULTRA_VERBOSE);
            $sent = \time();

            if ($ackCount) {
                $connection->ack_queue = \array_slice($connection->ack_queue, $ackCount);
            }

            foreach ($keys as $key => $message_id) {
                $connection->outgoing_messages[$message_id] =& $connection->pending_outgoing[$key];
                if (isset($connection->outgoing_messages[$message_id]['promise'])) {
                    $connection->new_outgoing[$message_id] = $message_id;
                    $connection->outgoing_messages[$message_id]['sent'] = $sent;
                }
                if (isset($connection->outgoing_messages[$message_id]['send_promise'])) {
                    $connection->outgoing_messages[$message_id]['send_promise']->resolve(isset($connection->outgoing_messages[$message_id]['promise']) ? $connection->outgoing_messages[$message_id]['promise'] : true);
                    unset($connection->outgoing_messages[$message_id]['send_promise']);
                }
                unset($connection->pending_outgoing[$key]);
            }
        } while ($connection->pending_outgoing && !$skipped);
        if (empty($connection->pending_outgoing)) {
            $connection->pending_outgoing_key = 'a';
        }
        return $skipped;
    }
    /**
     * Get loop name.
     *
     * @return string
     */
    public function __toString(): string
    {
        return "write loop in DC {$this->datacenter}";
    }
}
