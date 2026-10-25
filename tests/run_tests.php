<?php

require_once __DIR__ . '/bootstrap.php';

$testClasses = [
    \TamaraPhpSdk\Tests\TamaraSignatureVerifierTest::class,
    \TamaraPhpSdk\Tests\TamaraPaymentValidatorTest::class,
    \TamaraPhpSdk\Tests\TamaraOrderStateMachineTest::class,
    \TamaraPhpSdk\Tests\TamaraSanitizerTest::class,
    \TamaraPhpSdk\Tests\TamaraOrderReferenceTest::class,
    \TamaraPhpSdk\Tests\TamaraWebhookProcessorTest::class,
    \TamaraPhpSdk\Tests\TamaraOrderBuilderTest::class,
    \TamaraPhpSdk\Tests\TamaraClientTest::class,
    \TamaraPhpSdk\Tests\TamaraServiceTest::class,
    \TamaraPhpSdk\Tests\TamaraLaravelProviderTest::class,
];

echo "==========================================\n";
echo " Running Tamara PHP SDK Security Tests\n";
echo "==========================================\n\n";

$passed = 0;
$failed = 0;

foreach ($testClasses as $testClass) {
    $name = basename(str_replace('\\', '/', $testClass));
    echo "Running {$name}... ";
    try {
        $test = new $testClass();
        $test->run();
        echo "PASSED\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "FAILED\n";
        echo "  Error: " . $e->getMessage() . "\n";
        echo "  File:  " . $e->getFile() . ":" . $e->getLine() . "\n";
        $failed++;
    }
}

echo "\n------------------------------------------\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "------------------------------------------\n";

exit($failed > 0 ? 1 : 0);

