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
}

