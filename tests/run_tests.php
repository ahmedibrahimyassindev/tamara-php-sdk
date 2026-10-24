<?php

require_once __DIR__ . '/bootstrap.php';

$testClasses = [
    \Tamara\Tests\TamaraSignatureVerifierTest::class,
    \Tamara\Tests\TamaraPaymentValidatorTest::class,
    \Tamara\Tests\TamaraOrderStateMachineTest::class,
    \Tamara\Tests\TamaraSanitizerTest::class,
    \Tamara\Tests\TamaraOrderReferenceTest::class,
    \Tamara\Tests\TamaraWebhookProcessorTest::class,
    \Tamara\Tests\TamaraOrderBuilderTest::class,
    \Tamara\Tests\TamaraClientTest::class,
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

