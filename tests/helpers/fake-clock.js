'use strict';
// Explicitly advance time; cancelled callbacks never run. Async continuations drain
// between timers, so delayed/deferred responses exercise the actual application races.
module.exports=function fakeClock(){
  let now=0,sequence=0;const timers=new Map();
  return {
    setTimeout(fn,delay=0){const id=++sequence;timers.set(id,{fn,due:now+Math.max(0,delay)});return id},
    clearTimeout(id){timers.delete(id)},
    now:()=>now,
    async tick(ms){
      const end=now+ms;
      for(let guard=0;guard<1000;guard++){
        await new Promise(resolve=>setImmediate(resolve));
        const next=[...timers].filter(([,t])=>t.due<=end).sort((a,b)=>a[1].due-b[1].due)[0];
        if(!next){now=end;return}
        now=next[1].due;timers.delete(next[0]);next[1].fn();
      }
      throw new Error('Timer loop');
    },
  };
};
