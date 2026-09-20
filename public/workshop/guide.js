/* Presentation only: explain settings without changing the profile. */
(() => {
  const help = {
    strikesPerAttack: 'Répartit la même attaque entre plusieurs frappes. Plus de frappes donne des touches moins puissantes et peut limiter les dégâts excédentaires.',
    capturable: 'Autorise la capture des blessés de cette unité si son camp perd et si le taux de prisonniers est supérieur à zéro.',
    maxRounds: 'Limite la durée du combat. Si les deux camps sont encore en lice, le critère de départage désigne le vainqueur.',
    surrenderEnabled: 'Arrête le combat lorsqu’un camp atteint le seuil de morts. Désactivée, la résolution continue jusqu’à une autre condition de fin.',
    surrenderDeadPercent: 'Proportion cumulée de morts parmi les effectifs initiaux. Le seuil est inclusif ; les blessés ne le déclenchent pas.',
    tieBreakCriterion: 'Compare les survivants à la fin du combat selon leur valeur économique ou la proportion de structure restante.',
    equalityPolicy: 'Décide du résultat uniquement quand le départage donne une égalité exacte. Un match nul ne produit pas de prisonniers.',
    lossCompressionPercent: 'Part des conséquences brutes conservée en sortie, avec arrondi inférieur par catégorie. 10 % conserve un dixième des morts, blessés et prisonniers.',
    capturePercent: 'Part des blessés capturables du vaincu transformée en prisonniers avant compression. 0 % désactive les captures.'
  };
  function annotate() {
    document.querySelectorAll('[data-field], [data-combat]').forEach(input => {
      if (input.dataset.explained) return;
      const message = help[input.dataset.field || input.dataset.combat];
      if (!message) return;
      input.dataset.explained = 'true';
      const label = input.closest('label');
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
