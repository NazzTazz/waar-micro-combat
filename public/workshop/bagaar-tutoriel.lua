-- @profile Tutoriel Bagaar
-- @author Équipe Bagaar
-- @version 1.0.0

-- Ce script est un exemple exécutable : il n'entreprend aucune action.
-- Copiez-le dans l'éditeur, modifiez-le, puis utilisez « Tester ».
-- Chaque joueur possède sa propre mémoire et ses propres paramètres.

goal = "Découvrir le SDK Bagaar"
method = "Observer sans agir."

-- Les paramètres sont déclarés sans appeler la stratégie next().
-- Leur valeur est fixée par joueur au lancement de l'ère.
function parameters()
  return {
    {name = "AGRESSIVITE", type = ParamType.slider,
     min = 0, max = 100, step = 5, default = 50,
     description = "Propension à chercher un combat"},
    {name = "SOIGNER", type = ParamType.toggle, default = true,
     description = "Autoriser les soins"}
  }
end

function next(observation)
  -- Temps simulé : observation.tick (une heure) et observation.totalTicks.
  -- observation.self décrit votre compte : armée, or, Glwaare, mine,
  -- infirmerie, attaques et défenses restantes, prisonniers, etc.
  -- observation.parameters.AGRESSIVITE est le réglage de CE joueur.
  -- observation.memory est une table personnelle et persistante (128 Kio JSON).
  -- Elle survit au reset et aux reprises de calcul ; une nouvelle ère la vide.
  local memory = observation.memory
  memory.visites = (memory.visites or 0) + 1

  -- observation.ranking : classement public, Glwaare, Rwaa, dernière présence.
  -- observation.targets : joueurs et villages avec leurs informations publiques.
  -- observation.rwaa : identifiant du Rwaa, ou nil.
  -- observation.reports : renseignements que VOUS avez obtenus par espionnage.
  -- Un rapport d'espionnage n'expose pas la composition adverse.
  -- observation.events : vos combats récents, avec pertes par unité et prisonniers.
  -- observation.attempts : actions déjà proposées dans ce tick, et résultats
  -- des groupes d'espionnage. Vérifiez-les pour éviter les répétitions.

  -- Exemples d'actions à essayer UNE À LA FOIS en remplaçant « return nil » :
  -- return {type = "mine"}
  -- return {type = "hospital"}
  -- return {type = "recruit", units = {soldier = 10, archer = 2}}
  -- return {type = "heal"}
  -- return {type = "spy", target = "village-20"}
  -- return {type = "spy", targets = {"axel", "bruno", "village-20"}}
  -- return {type = "attack", target = "village-20"}
  -- return {type = "autoSurrender", enabled = true}
  -- return {type = "surrender"} -- après neuf défaites défensives consécutives
  -- return {type = "reset"}     -- plus de 24 ticks depuis le dernier reset
  -- return {type = "abandon"}   -- le compte reste sur la carte comme frigo

  -- Le prochain next() voit les rapports des espions du groupe précédent.
  -- Maximum : 64 tentatives d'espionnage par joueur et par tick.
  -- Les attaques obéissent au quota du jeu hôte, jusqu'à 24 pour les chevaliers.
  -- Chaque cible est validée séparément : portée, coût et disponibilité.
  -- Le script ne connaît ni le ruleset caché ni l'armée des autres sans rapport.
  return nil
end

-- Réaction facultative après un combat. Mêmes règles, quotas et mémoire.
function after_combat(observation)
  -- Exemple : return {type = "heal"}
  return nil
end

-- Outils disponibles : math.abs/ceil/floor/max/min/sqrt/exp/log ;
-- string.find/format/len/lower/match/sub/upper ;
-- table.concat/insert/move/pack/remove/sort/unpack ;
-- assert, error, ipairs, next, pairs, pcall, select, tonumber, tostring, type.
-- Pas de require, fichier, réseau, horloge réelle ni math.random.
