<?php

namespace App\Game\Combat;

use App\Game\Combat\Numeric\CombatFixedPoint;
use App\Game\Combat\Preparation\PreparedCombatSide;
use App\Game\Random\AccuracySampler;
use App\Game\Random\StochasticEngineVersion;

final readonly class CombatSnapshot
{
    public const SCHEMA_VERSION='waar-combat-snapshot/2';
    public function __construct(public string $rulesetVersion,public int $seed,public PreparedCombatSide $attacker,public PreparedCombatSide $defender,
        public string $traceLevel='full',public StochasticEngineVersion $stochasticEngineVersion=StochasticEngineVersion::Lcg31NormalApproximationV1,public string $numericModelVersion=CombatFixedPoint::VERSION)
    {if(!in_array($traceLevel,['none','full'],true))throw new \InvalidArgumentException('Trace level must be none or full.');}
    /** @return array<string,mixed> */
    public function toArray():array{return ['schemaVersion'=>self::SCHEMA_VERSION,'rulesetVersion'=>$this->rulesetVersion,'seed'=>$this->seed,'traceLevel'=>$this->traceLevel,
        'stochasticEngineVersion'=>$this->stochasticEngineVersion->value,'accuracySamplerVersion'=>AccuracySampler::VERSION,'numericModelVersion'=>$this->numericModelVersion,
        'prepared'=>['attacker'=>$this->attacker->toArray(),'defender'=>$this->defender->toArray()]];}
}
