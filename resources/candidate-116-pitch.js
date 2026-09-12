(() => {
  'use strict';
  const data = JSON.parse(document.getElementById('pitch-data').textContent);
  const select = document.getElementById('duel');
  const chart = echarts.init(document.getElementById('chart'));
  const fmt = value => value.toLocaleString('fr-FR', {maximumFractionDigits: 2});
  for (const scenario of data.scenarios) {
    const option = document.createElement('option');
    option.value = scenario.id;
    option.textContent = scenario.label;
    select.append(option);
  }
  function render() {
    const scenario = data.scenarios.find(row => row.id === select.value);
    const colors = ['#087e83','#cb7734'];
    const series = scenario.sides.flatMap((side,i) => [
      {type:'custom',silent:true,data:[0],renderItem:(_,api) => ({type:'polygon',shape:{points:side.ellipse.map(p => api.coord(p))},style:{fill:colors[i]+'18',stroke:colors[i],lineWidth:1.5}})},
      {name:side.label,type:'lines',coordinateSystem:'cartesian2d',data:[{coords:[side.legacy,side.candidate]}],symbol:['none','arrow'],symbolSize:10,lineStyle:{color:colors[i],width:2,opacity:0.8},silent:true},
      {name:`${side.label} · Legacy ×20`,type:'scatter',symbol:'diamond',symbolSize:12,data:[side.legacy],itemStyle:{color:colors[i]}},
      {name:`${side.label} · 116`,type:'scatter',symbol:'circle',symbolSize:17,data:[side.candidate],itemStyle:{color:colors[i],borderColor:'#fff',borderWidth:2}}
    ]);
    chart.setOption({animation:false,
      grid:{left:58,right:24,top:48,bottom:65},
      tooltip:{trigger:'item',formatter:p => `${p.seriesName}<br>Victoires : ${fmt(p.value[0])} %<br>Pertes (indice affiché) : ${fmt(p.value[1])}`},
      xAxis:{type:'value',min:-10,max:110,interval:10,name:'Taux de victoire (%)',nameLocation:'middle',nameGap:32,axisLabel:{formatter:v => v>=0&&v<=100&&v%20===0?`${v}`:''}},
      yAxis:{type:'value',min:-10,max:110,interval:10,name:'Pertes : Legacy ×20 / 116 brut',nameGap:22,nameTextStyle:{align:'left'},axisLabel:{formatter:v => v>=0&&v<=100&&v%20===0?`${v}`:''}},
      series
    },true);
    const tbody = document.getElementById('values');
    tbody.replaceChildren();
    for (const side of scenario.sides) {
      const tr = document.createElement('tr');
      for (const value of [side.label,`${fmt(side.legacy[0])} % → ${fmt(side.candidate[0])} %`,`${fmt(side.legacyRawLoss)} %`,`${fmt(side.legacy[1])} → ${fmt(side.candidate[1])}`]) {
        const td = document.createElement('td');td.textContent=value;tr.append(td);
      }
      tbody.append(tr);
    }
    document.getElementById('duel-note').textContent = scenario.note;
  }
  select.addEventListener('change',render);
  window.addEventListener('resize',() => chart.resize());
  render();
})();
