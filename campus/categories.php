<?php
require_once 'config.php';
requireLogin();
$pageTitle = "Categories";
$activePage = "categories";
include "includes/header.php";
?>
<div class="app-shell">
    <?php include "includes/sidebar.php"; ?>

    <main class="main-content">
        <?php include "includes/topbar.php"; ?>

        <section class="page-content">
            <div class="page-header">
                <div>
                    <p class="eyebrow">Organize every rupee</p>
                    <h1>Categories</h1>
                    <p class="muted">Create your own income and expense categories.</p>
                </div>
                <button class="primary-btn" type="button" onclick="openCategoryModal()">
                    ＋ Add category
                </button>
            </div>

            <div class="category-grid" id="categoryGrid"></div>
        </section>

        <div class="transaction-modal" id="categoryModal" aria-hidden="true">
            <div class="transaction-modal-box category-modal-box">
                <button class="modal-close" type="button" onclick="closeCategoryModal()">×</button>

                <p class="eyebrow">Personalize your budget</p>
                <h2>Add category</h2>
                <p class="muted">Choose whether this category is for income or expenses.</p>

                <form id="categoryForm">
                    <label>
                        Category name
                        <input type="text" id="categoryName" placeholder="e.g. Freelance" maxlength="40" required>
                    </label>

                    <label>
                        Category type
                        <select id="categoryType" required>
                            <option value="expense">Expense</option>
                            <option value="income">Income</option>
                        </select>
                    </label>

                    <div class="transaction-form-actions">
                        <button class="cancel-btn" type="button" onclick="closeCategoryModal()">
                            <span class="cancel-icon">×</span>
                            Cancel
                        </button>
                        <button class="primary-btn full-btn" type="submit">Save category</button>
                    </div>
                </form>
            </div>
        </div>

        <?php include "includes/footer.php"; ?>
    </main>
</div>
<script>
    initializeCategoriesPage();
</script>
