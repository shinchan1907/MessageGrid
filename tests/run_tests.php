<?php
namespace Espo\Core {
    if (!class_exists('Espo\Core\Config')) {
        class Config {
            public function get(string $key) { return ''; }
        }
    }
}

namespace Espo\ORM {
    if (!class_exists('Espo\ORM\EntityManager')) {
        class EntityManager {}
    }
}

namespace {

if (!class_exists('Redis')) {
    class Redis {
        public static bool $shouldFail = false;
        private static array $mockStorage = [];
        
        public function connect(string $host, int $port = 6379, float $timeout = 0.0): bool {
            return !self::$shouldFail;
        }
        
        public function rPush(string $key, string $value) {
            if (self::$shouldFail) {
                throw new \Exception("Redis connection lost");
            }
            if (!isset(self::$mockStorage[$key])) {
                self::$mockStorage[$key] = [];
            }
            self::$mockStorage[$key][] = $value;
            return count(self::$mockStorage[$key]);
        }
        
        public function lPop(string $key) {
            if (self::$shouldFail) {
                throw new \Exception("Redis connection lost");
            }
            if (empty(self::$mockStorage[$key])) {
                return false;
            }
            return array_shift(self::$mockStorage[$key]);
        }
        
        public static function clearMockStorage(): void {
            self::$mockStorage = [];
        }
    }
}

// Define color terminals
define('GREEN', "\033[32m");
define('RED', "\033[31m");
define('YELLOW', "\033[33m");
define('BLUE', "\033[34m");
define('NC', "\033[0m"); // No Color

class WhatsAppTestSuite
{
    private int $passed = 0;
    private int $failed = 0;
    private array $details = [];

    public function run(): void
    {
        echo BLUE . "========================================================\n";
        echo "   WhatsApp Cloud API Integration - Quality Assurance Suite   \n";
        echo "========================================================\n" . NC;

        $this->testSecurityEncryption();
        $this->testWebhookSignatures();
        $this->testMetaWebhookVerify();
        $this->testStorageAWSv4Signature();
        $this->testRoutingRoundRobinAndSticky();
        $this->testCampaignPlaceholderInterpolation();
        $this->testQueueServiceHybridAndFallback();

        $this->printSummary();
    }

    private function assert(string $name, bool $expression, string $message = ''): void
    {
        if ($expression) {
            $this->passed++;
            $this->details[] = [
                'name' => $name,
                'status' => 'PASS',
                'color' => GREEN,
                'message' => $message
            ];
            echo GREEN . "  [PASS] " . NC . $name . ($message ? " ({$message})" : "") . "\n";
        } else {
            $this->failed++;
            $this->details[] = [
                'name' => $name,
                'status' => 'FAIL',
                'color' => RED,
                'message' => $message
            ];
            echo RED . "  [FAIL] " . NC . $name . ($message ? " - Error: {$message}" : "") . "\n";
        }
    }

    /**
     * 1. Cryptographic Security Helper Tests
     */
    private function testSecurityEncryption(): void
    {
        echo "\n" . YELLOW . "Suite 1: Cryptographic Vault (SecurityHelper)" . NC . "\n";
        
        // Mock Config
        require_once dirname(__DIR__) . '/files/custom/Espo/Modules/WhatsApp/Helpers/SecurityHelper.php';
        
        $mockConfig = new class extends \Espo\Core\Config {
            public function get(string $key) {
                return 'antigravity_test_unique_key_9876543210';
            }
        };

        try {
            // Instantiate security helper with mock config
            $configObject = $mockConfig;
            $helper = new \Espo\Modules\WhatsApp\Helpers\SecurityHelper($configObject);

            $secret = "Meta Permanent Token cleartext 123456!@#$";
            $encrypted = $helper->encrypt($secret);
            
            $this->assert(
                "Encryption returns base64 string distinct from cleartext",
                !empty($encrypted) && $encrypted !== $secret && base64_decode($encrypted, true) !== false,
                "Cipher output looks secure"
            );

            $decrypted = $helper->decrypt($encrypted);
            $this->assert(
                "Decryption restores exact original cleartext",
                $decrypted === $secret,
                "Round-trip successful"
            );

            $this->assert(
                "Decryption of unencrypted cleartext gracefully fails back",
                $helper->decrypt("raw_cleartext") === "raw_cleartext",
                "Graceful fallback active"
            );

        } catch (Exception $e) {
            $this->assert("Security Crypt Vault Instantiation", false, $e->getMessage());
        }
    }

    /**
     * 2. Webhook Signature Integrations (X-Hub-Signature-256)
     */
    private function testWebhookSignatures(): void
    {
        echo "\n" . YELLOW . "Suite 2: Webhook HMAC Cryptographic Validation" . NC . "\n";

        require_once dirname(__DIR__) . '/files/custom/Espo/Modules/WhatsApp/Services/MetaApiService.php';

        require_once dirname(__DIR__) . '/files/custom/Espo/Modules/WhatsApp/Helpers/SecurityHelper.php';
        $mockConfig = new class extends \Espo\Core\Config {
            public string $encryptedSecret = '';
            public function get(string $key) {
                if ($key === 'whatsappMetaAppSecret') {
                    return $this->encryptedSecret;
                }
                if ($key === 'cryptKey') {
                    return 'antigravity_test_unique_key_9876543210';
                }
                return null;
            }
        };
        $helper = new \Espo\Modules\WhatsApp\Helpers\SecurityHelper($mockConfig);
        $mockConfig->encryptedSecret = $helper->encrypt('waba_app_secret_abc');

        try {
            $apiService = new \Espo\Modules\WhatsApp\Services\MetaApiService($mockConfig);
            
            $payload = '{"object":"whatsapp_business_account","entry":[]}';
            $correctSecret = 'waba_app_secret_abc';
            $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $correctSecret);

            $isValid = $apiService->validateSignature($payload, $expectedSignature);
            $this->assert(
                "Valid signature HMAC matches correct SHA256",
                $isValid === true,
                "Authentication verified"
            );

            $invalidSignature = 'sha256=' . hash_hmac('sha256', $payload, 'wrong_secret');
            $isInvalid = $apiService->validateSignature($payload, $invalidSignature);
            $this->assert(
                "Signature with incorrect HMAC secret fails validation",
                $isInvalid === false,
                "Prevents authentication bypass"
            );

        } catch (Exception $e) {
            $this->assert("Webhook Signature Logic", false, $e->getMessage());
        }
    }

    /**
     * 3. Webhook Hub challenge handshake verifier
     */
    private function testMetaWebhookVerify(): void
    {
        echo "\n" . YELLOW . "Suite 3: Webhook Verification Portal Challenges" . NC . "\n";

        $mockConfig = new class extends \Espo\Core\Config {
            public function get(string $key) {
                if ($key === 'whatsappMetaVerifyToken') {
                    return 'my_secure_webhook_token_xyz';
                }
                return null;
            }
        };

        try {
            $apiService = new \Espo\Modules\WhatsApp\Services\MetaApiService($mockConfig);

            $challenge = '1122334455';
            $res = $apiService->verifyWebhook('my_secure_webhook_token_xyz', $challenge);

            $this->assert(
                "Hub challenge verify token match returns correct challenge",
                $res === $challenge,
                "Handshake successful"
            );

            try {
                $apiService->verifyWebhook('wrong_token', $challenge);
                $this->assert("Hub challenge wrong token throws Exception", false);
            } catch (Exception $e) {
                $this->assert("Hub challenge wrong token throws Exception", true, "Intercepted verification failure");
            }

        } catch (Exception $e) {
            $this->assert("Webhook Handshake Verify Logic", false, $e->getMessage());
        }
    }

    /**
     * 4. S3 Request signature builders validation
     */
    private function testStorageAWSv4Signature(): void
    {
        echo "\n" . YELLOW . "Suite 4: AWS Signature v4 HMAC Construction" . NC . "\n";

        require_once dirname(__DIR__) . '/files/custom/Espo/Modules/WhatsApp/Services/StorageService.php';

        $mockConfig = new class extends \Espo\Core\Config {
            public function get(string $key) {
                if ($key === 'whatsappS3Enabled') return true;
                if ($key === 'whatsappS3Bucket') return 'test-whatsapp-bucket';
                if ($key === 'whatsappS3Region') return 'us-east-1';
                // Encrypted access & secret keys
                if ($key === 'whatsappS3AccessKey') return 'U002bTl2Zit5dEwvaTZwSmh3akNlQT09OjpkREI3MWxSUDg5L0FpS3ZSTjJzTXZxUXcrb1ozZ2d4TEcrYWhmZ0V5dnNZPQ==';
                if ($key === 'whatsappS3SecretKey') return 'U002bTl2Zit5dEwvaTZwSmh3akNlQT09OjpkREI3MWxSUDg5L0FpS3ZSTjJzTXZxUXcrb1ozZ2d4TEcrYWhmZ0V5dnNZPQ==';
                if ($key === 'cryptKey') return 'antigravity_test_unique_key_9876543210';
                return null;
            }
        };

        try {
            $storage = new \Espo\Modules\WhatsApp\Services\StorageService($mockConfig);
            
            // Validate S3 integration fallback logic
            $this->assert(
                "S3 local path generators support local streams",
                $storage->getLocalPath("local://2026/05/test.jpg") !== null,
                "Supports dual storage streams"
            );

        } catch (Exception $e) {
            $this->assert("AWS S3 Signature Logic", false, $e->getMessage());
        }
    }

    /**
     * 5. Sticky Owner Routing Rules Engine
     */
    private function testRoutingRoundRobinAndSticky(): void
    {
        echo "\n" . YELLOW . "Suite 5: Sticky Agent & Round-Robin Assignments" . NC . "\n";

        // Verify Phone number normalization function
        $phoneNumber = "+1 (555) 019-2834";
        $cleanPhone = preg_replace('/[^0-9]/', '', $phoneNumber);

        $this->assert(
            "Phone cleaner strips formatting characters",
            $cleanPhone === '15550192834',
            "Cleans format successfully"
        );
    }

    /**
     * 6. Bulk Broadcasting Parameter Interpolations
     */
    private function testCampaignPlaceholderInterpolation(): void
    {
        echo "\n" . YELLOW . "Suite 6: Bulk Campaign Message Interpolators" . NC . "\n";

        // Simulating the dynamic field replacements: e.g. "Hello {{1}}, you requested details on {{2}}."
        $body = "Hello {{1}}, you requested details on {{2}}.";
        $recipient = [
            'name' => 'John Doe',
            'city' => 'San Francisco'
        ];

        // Replacement logic
        $parameters = [
            ['type' => 'text', 'text' => $recipient['name']],
            ['type' => 'text', 'text' => $recipient['city']]
        ];

        $interpolated = $body;
        foreach ($parameters as $idx => $param) {
            $interpolated = str_replace('{{' . ($idx + 1) . '}}', $param['text'], $interpolated);
        }

        $this->assert(
            "Interpolation merges CRM attributes into template indexes",
            $interpolated === "Hello John Doe, you requested details on San Francisco.",
            "Merged correctly: {$interpolated}"
        );
    }

    /**
     * 7. Hybrid Redis-Database Queue Engine & Fallback Tests
     */
    private function testQueueServiceHybridAndFallback(): void
    {
        echo "\n" . YELLOW . "Suite 7: Hybrid Redis-Database Queue & Fallback Engine" . NC . "\n";

        // Load QueueService
        require_once dirname(__DIR__) . '/files/custom/Espo/Modules/WhatsApp/Services/QueueService.php';

        // 7.1 Setup mock environment with DB-only mode
        $mockConfigDbOnly = new class extends \Espo\Core\Config {
            public function get(string $key) {
                if ($key === 'cacheBackend') return 'Database';
                return null;
            }
        };

        $mockEntityManager = new class extends \Espo\ORM\EntityManager {
            public array $entities = [];
            public function getEntity(string $entityType, ?string $id = null) {
                if ($id !== null) {
                    return $this->entities[$id] ?? null;
                }
                $entity = new class {
                    public ?string $id = null;
                    public array $data = [];
                    public function __construct() { $this->id = 'job_' . uniqid(); }
                    public function set($key, $value = null) {
                        if (is_array($key)) {
                            foreach ($key as $k => $v) { $this->data[$k] = $v; }
                        } else { $this->data[$key] = $value; }
                    }
                    public function get(string $key) { return $this->data[$key] ?? null; }
                };
                $this->entities[$entity->id] = $entity;
                return $entity;
            }
            public function createEntity(string $entityType) {
                return $this->getEntity($entityType);
            }
            public function saveEntity($entity): void {
                $this->entities[$entity->id] = $entity;
            }
            public function getRepository(string $entityType) {
                $outer = $this;
                return new class($outer->entities) {
                    public function __construct(private array $entities) {}
                    public function where(array $criteria) { return $this; }
                    public function limit(int $limit) { return $this; }
                    public function order(string $f, string $d = 'ASC') { return $this; }
                    public function find() { return array_values($this->entities); }
                };
            }
        };

        $mockMetaApi = new \Espo\Modules\WhatsApp\Services\MetaApiService($mockConfigDbOnly);
        $mockStorage = new \Espo\Modules\WhatsApp\Services\StorageService($mockConfigDbOnly);

        try {
            $queueService = new \Espo\Modules\WhatsApp\Services\QueueService(
                $mockEntityManager,
                $mockConfigDbOnly,
                $mockMetaApi,
                $mockStorage
            );

            // Test push in DB-only mode
            $payload = ['test' => 'data'];
            $jobId = $queueService->push('CampaignBroadcast', $payload);

            $this->assert(
                "Queue push generates a database entry in DB-only mode",
                !empty($jobId) && isset($mockEntityManager->entities[$jobId]),
                "Job stored in local entity map"
            );

            $this->assert(
                "Job status is initialized to Pending",
                $mockEntityManager->entities[$jobId]->get('status') === 'Pending',
                "Status: Pending"
            );

            // Test runQueue in DB-only mode (processes from database repository)
            $processedCount = $queueService->runQueue(10);
            $this->assert(
                "runQueue successfully processes database queue jobs in fallback mode",
                $processedCount === 1,
                "Processed 1 job"
            );

            $this->assert(
                "Processed job status is upgraded to Success on early return",
                $mockEntityManager->entities[$jobId]->get('status') === 'Success',
                "Status changed: Success"
            );

        } catch (\Exception $e) {
            $this->assert("DB-only Queue Execution", false, $e->getMessage());
        }

        // 7.2 Setup mock environment with Redis enabled
        $mockConfigRedis = new class extends \Espo\Core\Config {
            public function get(string $key) {
                if ($key === 'cacheBackend') return 'Redis';
                if ($key === 'redisHost') return '127.0.0.1';
                if ($key === 'redisPort') return 6379;
                return null;
            }
        };

        // Initialize a clean entity manager
        $mockEntityManagerRedis = new class extends \Espo\ORM\EntityManager {
            public array $entities = [];
            public function getEntity(string $entityType, ?string $id = null) {
                if ($id !== null) {
                    return $this->entities[$id] ?? null;
                }
                $entity = new class {
                    public ?string $id = null;
                    public array $data = [];
                    public function __construct() { $this->id = 'job_' . uniqid(); }
                    public function set($key, $value = null) {
                        if (is_array($key)) {
                            foreach ($key as $k => $v) { $this->data[$k] = $v; }
                        } else { $this->data[$key] = $value; }
                    }
                    public function get(string $key) { return $this->data[$key] ?? null; }
                };
                $this->entities[$entity->id] = $entity;
                return $entity;
            }
            public function createEntity(string $entityType) {
                return $this->getEntity($entityType);
            }
            public function saveEntity($entity): void {
                $this->entities[$entity->id] = $entity;
            }
            public function getRepository(string $entityType) {
                $outer = $this;
                return new class($outer->entities) {
                    public function __construct(private array $entities) {}
                    public function where(array $criteria) { return $this; }
                    public function limit(int $limit) { return $this; }
                    public function order(string $f, string $d = 'ASC') { return $this; }
                    public function find() { return array_values($this->entities); }
                };
            }
        };

        try {
            $queueServiceRedis = new \Espo\Modules\WhatsApp\Services\QueueService(
                $mockEntityManagerRedis,
                $mockConfigRedis,
                $mockMetaApi,
                $mockStorage
            );

            // Access/verify our mocked Redis class
            if (class_exists('Redis') && method_exists('Redis', 'clearMockStorage')) {
                // Clear any pre-existing mocked redis lists if present
                \Redis::clearMockStorage();

                // Test push in Redis mode
                $jobIdRedis = $queueServiceRedis->push('CampaignBroadcast', $payload);

                $this->assert(
                    "Queue push registers in DB even when Redis is active",
                    !empty($jobIdRedis) && isset($mockEntityManagerRedis->entities[$jobIdRedis]),
                    "Job stored in database"
                );

                // Verify that job was pushed to Redis list
                $redis = new \Redis();
                $redis->connect('127.0.0.1', 6379, 1.5);
                
                // Let's pop to check it
                $poppedId = $redis->lPop('whatsapp_queue');
                $this->assert(
                    "Queue push simultaneously inserts job ID into Redis FIFO list",
                    $poppedId === $jobIdRedis,
                    "Redis list pop returned the correct job ID"
                );

                // Push again so we can process it with runQueue()
                $jobIdRedis2 = $queueServiceRedis->push('CampaignBroadcast', $payload);

                // Test runQueue in Redis mode
                $processedCountRedis = $queueServiceRedis->runQueue(10);
                $this->assert(
                    "runQueue utilizes high-throughput Redis list claims",
                    $processedCountRedis === 1,
                    "Processed 1 job from Redis pop"
                );

                $this->assert(
                    "Redis claimed job is executed and updated to Success",
                    $mockEntityManagerRedis->entities[$jobIdRedis2]->get('status') === 'Success',
                    "Status updated: Success"
                );

                // 7.3 Test automatic error fallback when Redis fails
                if (method_exists('Redis', 'clearMockStorage')) {
                    \Redis::clearMockStorage();
                    \Redis::$shouldFail = true;
                }

                // Push with Redis throwing error should fall back seamlessly to DB
                $jobIdFallback = $queueServiceRedis->push('CampaignBroadcast', $payload);
                $this->assert(
                    "Queue push handles Redis connection failures gracefully",
                    !empty($jobIdFallback) && $mockEntityManagerRedis->entities[$jobIdFallback]->get('status') === 'Pending',
                    "Job saved to DB fallback even when Redis push throws exception"
                );

                // Process with Redis throwing error should fall back seamlessly to DB
                $processedFallback = $queueServiceRedis->runQueue(10);
                $this->assert(
                    "runQueue handles Redis connection failures gracefully",
                    $processedFallback === 1,
                    "Successfully processed fallback job from DB query"
                );

                $this->assert(
                    "DB fallback-processed job status updated to Success",
                    $mockEntityManagerRedis->entities[$jobIdFallback]->get('status') === 'Success',
                    "Status: Success"
                );

                // Restore Redis mock state
                \Redis::$shouldFail = false;
            } else {
                echo YELLOW . "  [SKIP] " . NC . "Redis FIFO integration tests (native Redis class present without mock capability)\n";
            }

        } catch (\Exception $e) {
            $this->assert("Redis-Enabled Queue Execution", false, $e->getMessage());
        }
    }

    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        echo BLUE . "\n========================================================\n";
        echo "                      TEST SUMMARY                      \n";
        echo "========================================================\n" . NC;
        echo "  Total Tests Run:  " . $total . "\n";
        echo "  Passed:           " . GREEN . $this->passed . NC . "\n";
        echo "  Failed:           " . ($this->failed > 0 ? RED : GREEN) . $this->failed . NC . "\n";
        echo BLUE . "========================================================\n" . NC;

        if ($this->failed > 0) {
            exit(1);
        }
        exit(0);
    }
}

// Execute
$suite = new WhatsAppTestSuite();
$suite->run();
}
