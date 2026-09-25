<?php

namespace App\Game\Combat;

use App\Game\Combat\Numeric\CombatFixedPoint;
use App\Game\Combat\Preparation\PreparedCombatSide;
use App\Game\Random\AccuracySampler;
use App\Game\Random\AddressedRandom;
use App\Game\Random\StochasticEngineVersion;

final readonly class CombatSnapshot
{
    public const SCHEMA_VERSION='waar-combat-snapshot/2';
    public function __construct(public string $rulesetVersion,public int $seed,public PreparedCombatSide $attacker,public PreparedCombatSide $defender,
        public string $traceLevel='full',public StochasticEngineVersion $stochasticEngineVersion=StochasticEngineVersion::Lcg31NormalApproximationV1,public string $numericModelVersion=CombatFixedPoint::VERSION,public ?array $armyIdentities=null)
    {
        if(!in_array($traceLevel,['none','full'],true))throw new \InvalidArgumentException('Trace level must be none or full.');
        if ($stochasticEngineVersion === StochasticEngineVersion::AddressedBinomialV1) {
            if ($armyIdentities === null || count($armyIdentities) !== 2
                || !in_array($armyIdentities['attacker'] ?? null, ['A','B'], true)
                || !in_array($armyIdentities['defender'] ?? null, ['A','B'], true)
                || $armyIdentities['attacker'] === $armyIdentities['defender']) throw new \InvalidArgumentException('Distinct A/B armyIdentities are required.');
        } elseif ($armyIdentities !== null) throw new \InvalidArgumentException('Legacy protocol cannot use armyIdentities.');
    }
    public function addressed(int $round, string $role): ?AddressedRandom
    {return $this->armyIdentities === null ? null : new AddressedRandom($this->seed, $this->armyIdentities[$role], $round);}
    /** @return array<string,mixed> */
    public function toArray():array{return ['schemaVersion'=>self::SCHEMA_VERSION,'rulesetVersion'=>$this->rulesetVersion,'seed'=>$this->seed,'traceLevel'=>$this->traceLevel,
        'stochasticEngineVersion'=>$this->stochasticEngineVersion->value,'accuracySamplerVersion'=>$this->armyIdentities===null?AccuracySampler::VERSION:AddressedRandom::VERSION,'numericModelVersion'=>$this->numericModelVersion,
        ...($this->armyIdentities===null?[]:['armyIdentities'=>$this->armyIdentities]),
        'prepared'=>['attacker'=>$this->attacker->toArray(),'defender'=>$this->defender->toArray()]];}
}
