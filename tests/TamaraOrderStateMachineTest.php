<?php

namespace Tamara\Tests;

use Tamara\Security\TamaraOrderStateMachine;

class TamaraOrderStateMachineTest
{
    public function run(): void
    {
        $this->testAllowedTransitions();
        $this->testTerminalStatesCannotBeOverwritten();
        $this->testOutofOrderTransitionsBlocked();
        $this->testIdempotentTransitionsAllowed();
        $this->testStaleTransitionClassification();
    }

    protected function testAllowedTransitions(): void
    {
        // new -> approved -> authorised -> fully_captured
        testExpect(TamaraOrderStateMachine::canTransition('new', 'approved'));
        testExpect(TamaraOrderStateMachine::canTransition('approved', 'authorised'));
        testExpect(TamaraOrderStateMachine::canTransition('authorised', 'fully_captured'));
        testExpect(TamaraOrderStateMachine::canTransition('fully_captured', 'refunded'));
    }

    protected function testTerminalStatesCannotBeOverwritten(): void
    {
        // Terminal states cannot transition to anything
        testExpect(!TamaraOrderStateMachine::canTransition('declined', 'approved'));
        testExpect(!TamaraOrderStateMachine::canTransition('cancelled', 'authorised'));
        testExpect(!TamaraOrderStateMachine::canTransition('expired', 'approved'));
        testExpect(!TamaraOrderStateMachine::canTransition('refunded', 'captured'));
    }

    protected function testOutofOrderTransitionsBlocked(): void
    {
        // Older events arriving after newer states must be blocked
        testExpect(!TamaraOrderStateMachine::canTransition('fully_captured', 'approved'));
        testExpect(!TamaraOrderStateMachine::canTransition('fully_captured', 'authorised'));
        testExpect(!TamaraOrderStateMachine::canTransition('fully_captured', 'cancelled'));
    }

    protected function testIdempotentTransitionsAllowed(): void
    {
        testExpect(TamaraOrderStateMachine::canTransition('authorised', 'authorised'));
        testExpect(TamaraOrderStateMachine::canTransition('fully_captured', 'captured'));
    }

    protected function testStaleTransitionClassification(): void
    {
        testExpect(TamaraOrderStateMachine::isStaleTransition('declined', 'approved'), 'Terminal current status is stale.');
        testExpect(TamaraOrderStateMachine::isStaleTransition('fully_captured', 'approved'), 'A lower ranked target is stale.');
        testExpect(!TamaraOrderStateMachine::isStaleTransition('approved', 'partially_refunded'), 'A higher ranked target may become valid later.');
        testExpect(!TamaraOrderStateMachine::isStaleTransition('processing', 'approved'), 'Unknown current status is not classified as stale.');
        testExpect(!TamaraOrderStateMachine::isStaleTransition(null, 'approved'), 'A known target may become valid from an unset status.');
        testExpect(!TamaraOrderStateMachine::isKnownStatus('processing'));
        testExpect(TamaraOrderStateMachine::isKnownStatus(null));
    }
}

