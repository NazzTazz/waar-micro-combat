"""Generate configurations only. Python 3 standard library; no simulations.
Run: python generate_plans.py [reports/campaign-manual-sol/profile.json]
Outputs beside this script. Install this directory as experiments/campagne-coeur.
"""
import csv, hashlib, itertools, json, pathlib, sys
from decimal import Decimal, ROUND_HALF_UP
ROOT=pathlib.Path(__file__).resolve().parent
SOURCE=pathlib.Path(sys.argv[1]) if len(sys.argv)>1 else ROOT/'reference-profile.json'
raw=SOURCE.read_bytes(); P=json.loads(raw)
assert P['schemaVersion']=='waar-engine-profile/0.2'
assert Decimal(P['combat']['woundDamageThreshold'])==Decimal('0.2'), 'Unexpected wound threshold; do not silently alter profile'
T=list(P['units']); B=[12000,120000,360000]; N=2000
plans=[]; coverage=[]
def dec(x):return format(Decimal(str(x)).normalize(),'f')
def unique(xs):
 out=[]; seen=set()
 for x in xs:
  key=json.dumps(x,sort_keys=True)
  if key not in seen:out.append(x);seen.add(key)
 return out

def budget(shares,b):return {'mode':'budgetShares','budget':b,'shares':shares,'fillRemainderWith':None}
def fixed(shares,b):return {'mode':'explicit','units':{t:int(Decimal(b)*Decimal(str(shares.get(t,0)))/Decimal(P['units'][t]['cost'])) for t in T}}
def scenario(id,a,b,wa='neutral',wb='neutral'):
 return {'id':id.lower(),'directions':'both','weather':{'A':wa,'B':wb},'modifiers':{'A':[],'B':[]},'armies':{'A':a,'B':b},'compositionVariants':[{'id':'reference','operations':[]}]}
def monos(affected=None,fixed_counts=False):
 out=[]
 for b in B:
  for a,c in itertools.combinations_with_replacement(T,2):
   if affected and affected not in (a,c):continue
   f=fixed if fixed_counts else budget
   out.append(scenario(f'MONO-B{b}-{a}-{c}',f({a:1},b),f({c:1},b)))
 return out

def emit(name,scenarios,axes=None,crosses=None,variants=None,phase='M'):
 axes=axes or {}; crosses=crosses or {'singles':True,'pairs':[],'triplets':[]}
 # With one axis (or an explicitly supplied complete Cartesian product),
 # each grid contains the exact reference. Counts precede engine deduplication.
 count=variants if variants is not None else (len(next(iter(axes.values()))['values']) if axes else 1)
 configs=len(scenarios)*count; fights=configs*2*N
 plan={'schemaVersion':'waar-parametric-campaign/1','profile':'../../reports/campaign-manual-sol/profile.json','output':f'../../reports/campagne-coeur/{name}','sampling':{'repetitions':N,'baseSeed':42,'batchSize':100},'limits':{'maxCombats':fights},'axes':axes,'crosses':crosses,'scenarios':scenarios}
 (ROOT/(name+'.json')).write_text(json.dumps(plan,ensure_ascii=False,indent=2)+'\n')
 plans.append({'plan':name+'.json','phase':phase,'scenarios':len(scenarios),'configurationsBeforeRuntimeDedup':configs,'combatsBeforeRuntimeDedup':fights,'status':'generated; CLI preview required'})
 for key,axis in axes.items():coverage.append({'path':axis['path'],'plan':name+'.json','values':json.dumps(axis['values'],ensure_ascii=False),'status':'planned; current validator must confirm'})

def axis_plan(name,path,values,ref,scenarios,phase='M'):
 grid=unique([ref]+values)
 extra=0
 if path.startswith('relations.'):
  source,target=path[len('relations.'):-len('.factor')].split('>')
  extra=0 if any(r['acting']==source and r['target']==target for r in P['relations']) else 1
 emit(name,scenarios,{'value':{'path':path,'values':grid}},variants=len(grid)+extra,phase=phase)

emit('plan-reference',monos(),phase='M0')
small=[scenario(f'D1-{t}-n{n}',{'mode':'explicit','units':{t:n}},{'mode':'explicit','units':{t:n}}) for t in T for n in [1,5,10,50,100]]
emit('D1-small-mirrors',small,phase='D1')
for t in T:
 sc=[scenario(f'D2-{t}-n{n}',{'mode':'explicit','units':{t:n}},{'mode':'explicit','units':{t:n}}) for n in ([5,100] if t=='archer' else [100])]
 defense=unique([P['units'][t]['defendingEfficiency']]+[dec(x) for x in [.5,.75,1,1.25,1.5,1.75,2]])
 rounds=unique([P['combat']['maxRounds'],1,2])
 emit('D2-defense-'+t,sc,{'defense':{'path':f'units.{t}.defendingEfficiency','values':defense},'rounds':{'path':'combat.maxRounds','values':rounds}}, {'singles':True,'pairs':[['defense','rounds']],'triplets':[]},len(defense)*len(rounds),phase='D2')

multipliers=[Decimal(x) for x in ['.5','.75','1','1.5','2']]
for t in T:
 for field in ['attack','structure','baseAccuracy','accuracySpread','strikesPerAttack','defendingEfficiency','cost','capturable']:
  ref=P['units'][t][field]
  if field in ('attack','structure'):values=[dec(Decimal(ref)*x) for x in multipliers]
  elif field=='baseAccuracy':values=[dec(x) for x in [0,.05,.1,.2,.35,.5,.7,.9,1]]
  elif field=='accuracySpread':values=[dec(x) for x in [0,.05,.1,.2,.4]]
  elif field=='strikesPerAttack':values=[1,2,3,5,8,12,16,24,32]
  elif field=='defendingEfficiency':values=[dec(x) for x in [.5,.75,1,1.25,1.5,1.75,2]]
  elif field=='cost':values=[int((Decimal(ref)*x).quantize(Decimal(1),rounding=ROUND_HALF_UP)) for x in multipliers]
  else:values=[False,True]
  for mode in (['fixed-counts','fixed-budget'] if field=='cost' else ['fixed-counts']):
   axis_plan(f'M-unit-{t}-{field}-{mode}',f'units.{t}.{field}',values,ref,monos(t,mode=='fixed-counts'))

combat={'maxRounds':[1,2,3,5,10,20,30],'tieBreakCriterion':['economic','structure'],'equalityPolicy':['defender','draw'],'lossCompressionPercent':[0,5,25,50,75,100],'capturePercent':[0,10,24,50],'woundDamageThreshold':[dec(x) for x in [0,.01,.05,.1,.2,.3,.5,.75,1]]}
for field,values in combat.items():axis_plan('M-combat-'+field,'combat.'+field,values,P['combat'][field],monos(fixed_counts=True))
surr={'enabled':P['combat']['surrenderEnabled'],'deadPercent':P['combat']['surrenderDeadPercent']}
axis_plan('M-combat-surrender','combat.surrender',[{'enabled':False}]+[{'enabled':True,'deadPercent':x} for x in [5,10,20,35,50,75,100]],surr,monos(fixed_counts=True))
for a,b in itertools.permutations(T,2):
 ref=next((r['factor'] for r in P['relations'] if r['acting']==a and r['target']==b),'1')
 sc=[scenario(f'COUNTER-{a}-{b}-B{v}',fixed({a:1},v),fixed({b:1},v)) for v in B]
 axis_plan(f'M-counter-{a}-{b}',f'relations.{a}>{b}.factor',[dec(x) for x in [0,.5,1,1.5,2,3,5,10]],ref,sc)

# Each named weather is retained, including reference weather controls.
for weather in P['weather']:
 sc=[]
 for base in monos(fixed_counts=True):
  for scope in ['A','B','both']:
   row=json.loads(json.dumps(base));row['id']+=f'-W{weather}-{scope}'
   row['weather']={'A':weather if scope in ('A','both') else 'neutral','B':weather if scope in ('B','both') else 'neutral'};sc.append(row)
 emit('M-weather-preset-'+weather,sc,phase='W')
# All actual weather cells, not only currently nonneutral cells, are represented.
# Single-sided activation isolates the changed cell from weather on the opponent.
for weather,units in P['weather'].items():
 for t,fields in units.items():
  for field,ref in fields.items():
   sc=[scenario(f'WFACTOR-{weather}-{t}-{field}-{op}-B{b}',fixed({t:1},b),fixed({op:1},b),weather,('cloudy' if weather=='neutral' else 'neutral')) for b in B for op in T]
   axis_plan(f'W-factor-{weather}-{t}-{field}',f'weather.{weather}.{t}.{field}',[dec(x) for x in [0,.25,.5,.75,.875,1]],ref,sc,phase='W')

for t in T:
 if t=='soldier':continue
 for mode in ['replace','add']:
  sc=[]
  vals=[0,5,10,20,35,50,65,80,90,100] if mode=='replace' else [0,5,10,20,35,50,100]
  for b in B:
   for op in T:
    for percent in vals:
     if mode=='replace':a=budget({'soldier':percent/100,t:(100-percent)/100},b)
     else:
      a=fixed({t:1},b);a['units']['soldier']=int(Decimal(b)*Decimal(percent)/100/Decimal(P['units']['soldier']['cost']))
     sc.append(scenario(f'E-{mode}-{t}-B{b}-s{percent}-vs-{op}',a,fixed({op:1},b)))
  emit(f'E-{mode}-{t}',sc,phase='E')

opponents=[(t,{t:1}) for t in T]+[('balanced',{t:.25 for t in T})]
compositions=[dict(zip(T,[a/4,b/4,c/4,(4-a-b-c)/4])) for a in range(5) for b in range(5-a) for c in range(5-a-b)]
assert len(compositions)==35
for label,comps in [('simplex',compositions),('archer-cut',[{'soldier':(60-x)/100,'spearman':.2,'archer':x/100,'knight':.2} for x in [0,10,20,30,40,50,60]])]:
 sc=[scenario(f'X-{label}-{i:02d}-B{b}-vs-{op}',budget(shares,b),fixed(osh,b)) for b in B for i,shares in enumerate(comps) for op,osh in opponents]
 emit('X-'+label,sc,phase='X')

manifest={'profile':{'label':P['label'],'id':P['id'],'sha256':hashlib.sha256(raw).hexdigest()},'status':'counts calculated from explicit grids; not CLI-validated; runtime dedup may reduce','totalPlans':len(plans),'totalConfigurationsBeforeRuntimeDedup':sum(p['configurationsBeforeRuntimeDedup'] for p in plans),'totalCombatsBeforeRuntimeDedup':sum(p['combatsBeforeRuntimeDedup'] for p in plans),'combatsExecuted':0,'plans':plans,'deferred':['D3 exact boundaries and traces','D4 controlled equality contexts','D5 targeted probability controls','selected pair/triple interactions','adaptive refinement','independent confirmation']}
(ROOT/'campaign-manifest.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n')
for name,rows in [('preview-estimate.csv',plans),('coverage-planned.csv',coverage)]:
 with (ROOT/name).open('w',newline='') as f:
  w=csv.DictWriter(f,fieldnames=list(rows[0]));w.writeheader();w.writerows(rows)
print(json.dumps({k:v for k,v in manifest.items() if k not in ('plans',)},ensure_ascii=False,indent=2))
