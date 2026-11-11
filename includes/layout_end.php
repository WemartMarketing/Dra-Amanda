        </main>
        <footer class="site-legal">WeMart Sales · Dados tratados conforme a LGPD — <a href="/privacidade.php">privacidade e uso dos dados</a></footer>
    </div>
</div>

<?php $principaisMobile = [$ehGestor ? 'dashboard' : 'inicio', 'leads', 'kanban', 'agenda']; ?>
<nav class="bottom-nav" aria-label="Navegação principal">
    <?php if ($ehGestor): ?>
        <a href="/pages/dashboard.php" class="<?= $navAtivo === 'dashboard' ? 'active' : '' ?>"><?= icon('chart') ?>Dashboard</a>
    <?php else: ?>
        <a href="/pages/inicio.php" class="<?= $navAtivo === 'inicio' ? 'active' : '' ?>"><?= icon('home') ?>Início</a>
    <?php endif; ?>
    <a href="/pages/leads.php" class="<?= $navAtivo === 'leads' ? 'active' : '' ?>"><?= icon('list') ?>Leads</a>
    <a href="/pages/kanban.php" class="<?= $navAtivo === 'kanban' ? 'active' : '' ?>"><?= icon('kanban') ?>Kanban</a>
    <a href="/pages/agenda.php" class="<?= $navAtivo === 'agenda' ? 'active' : '' ?>"><?= icon('calendar') ?>Agenda</a>
    <button type="button" data-open-modal="menu-mais" class="<?= !in_array($navAtivo, $principaisMobile, true) ? 'active' : '' ?>"><?= icon('more') ?>Mais</button>
</nav>

<div class="modal-overlay modal-sheet" id="menu-mais" hidden>
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="menu-mais-titulo">
        <button type="button" class="overlay-close" data-close-modal="menu-mais" aria-label="Fechar">&times;</button>
        <h2 id="menu-mais-titulo">Menu</h2>
        <nav class="menu-mais-lista">
            <?php foreach ($navItems as $item): if (in_array($item['key'], $principaisMobile, true)) continue; ?>
                <a href="<?= $item['href'] ?>" class="<?= $navAtivo === $item['key'] ? 'active' : '' ?>"><?= icon($item['icon']) ?><?= htmlspecialchars($item['label']) ?></a>
            <?php endforeach; ?>
            <a href="/logout.php"><?= icon('logout') ?>Sair</a>
        </nav>
    </div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>

<script src="/assets/js/app.js"></script>
</body>
</html>
