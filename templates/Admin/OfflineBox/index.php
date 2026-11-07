<?php
/**
 * OfflineBox validation page. Spec: offlineBox/README.md
 *
 * @var \App\View\AppView $this
 * @var array $check
 * @var array $install
 * @var array $levels
 */
$elements = '2026/admin/offlineBox/index_';
?>
<div class="container-fluid">
    <div class="row g-3">
        <div class="col-12">
            <h1 class="h3">OfflineBox</h1>
            <?= $this->element($elements . 'summary'); ?>
        </div>
        <div class="col-12">
            <?= $this->element($elements . 'databases'); ?>
        </div>
        <div class="col-12 col-lg-6">
            <?= $this->element($elements . 'rows', ['title' => 'Environment', 'rows' => $check['environment']]); ?>
        </div>
        <div class="col-12 col-lg-6">
            <?= $this->element($elements . 'rows', ['title' => 'Storage', 'rows' => $check['storage']]); ?>
        </div>
        <div class="col-12">
            <?= $this->element($elements . 'install'); ?>
        </div>
    </div>
</div>
