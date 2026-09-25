/* Presentation only: explain settings without changing the profile. */
(() => {
  const help = {
    capturable: 'Autorise la capture des blessés de cette unité si son camp perd et si le taux de prisonniers est supérieur à zéro.',
    maxRounds: 'Limite la durée du combat. Si les deux camps sont encore en lice, le critère de départage désigne le vainqueur.',
    surrenderEnabled: 'Arrête le combat lorsqu’un camp atteint le seuil de morts. Désactivée, la résolution continue jusqu’à une autre condition de fin.',
    surrenderDeadPercent: 'À 0, la reddition est désactivée. Sinon, seuil de morts parmi les effectifs initiaux ; les blessés ne comptent pas.',
    lossCompressionPercent: 'Fréquence de conservation des conséquences brutes. À 5 %, chaque mort, blessé ou prisonnier a 5 % de chances de rester en sortie : les nombres entiers varient selon la seed.',
    capturePercent: 'Probabilité pour chaque blessé capturable du vaincu de devenir prisonnier avant compression. 0 % désactive les captures.',
    woundDamageThreshold: 'Une unité est blessée lorsque ses dégâts cumulés dépassent ce pourcentage de sa structure maximale. Elle continue de combattre normalement.'
  };
  function annotate() {
    document.querySelectorAll('[data-field], [data-combat]').forEach(input => {
      if (input.dataset.explained) return;
      const message = help[input.dataset.field || input.dataset.combat];
      if (!message) return;
      input.dataset.explained = 'true';
      const label = input.closest('label');
      if (input.dataset.combat === 'surrenderEnabled') {label.title = message;input.title = message;return;}
      label.querySelectorAll('small').forEach(node => node.remove());
      const note = document.createElement('small');
      note.className = 'setting-help';
      note.id = 'help-' + (input.dataset.field || input.dataset.combat) + '-' + (input.closest('[data-unit]')?.dataset.unit || 'combat');
      note.textContent = message;
      label.append(note);
      input.setAttribute('aria-describedby', note.id);
    });
  }
  new MutationObserver(annotate).observe(document.querySelector('main'), {childList: true, subtree: true});
  window.addEventListener('message', event => {
    const frame = document.querySelector('#t27-editor');
    if (event.source !== frame?.contentWindow || event.origin !== location.origin || event.data?.type !== 'waar-workshop-height') return;
    if (Number.isFinite(event.data.height)) frame.style.height = Math.max(600, Math.min(20000, event.data.height)) + 'px';
  });
  annotate();
})();
