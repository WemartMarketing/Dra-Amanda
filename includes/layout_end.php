        </main>
    </div>
</div>

<nav class="bottom-nav">
    <a href="/pages/inicio.php" class="<?= $activeNav === 'inicio' ? 'active' : '' ?>"><?= icon('home') ?>Início</a>
    <a href="/pages/kanban.php" class="<?= in_array($activeNav, ['kanban', 'ficha']) ? 'active' : '' ?>"><?= icon('kanban') ?>Leads</a>
    <a href="/pages/kanban.php" class="<?= $activeNav === 'atender' ? 'active' : '' ?>"><?= icon('phone') ?>Atender</a>
    <a href="/pages/relatorios.php" class="<?= $activeNav === 'relatorios' ? 'active' : '' ?>"><?= icon('chart') ?>Relatórios</a>
</nav>

<script src="/assets/js/app.js"></script>
</body>
</html>
