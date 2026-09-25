document.addEventListener("DOMContentLoaded", function () {
    initializeAdminDashboard();
});

function adminCurrency(value) {
    return "Rs. " + Number(value || 0).toLocaleString("en-PK", {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2
    });
}

function adminReadJson(key, fallback) {
    const saved = localStorage.getItem(key);

    if (!saved) {
        return fallback;
    }

    try {
        return JSON.parse(saved);
    } catch (error) {
        return fallback;
    }
}

function initializeAdminDashboard() {
    const dateElement = document.getElementById("adminDateTime");

    if (dateElement) {
        updateAdminDateTime();
        setInterval(updateAdminDateTime, 60000);
    }

    const users = getAdminUsers();
    const transactions = getAllTransactionsForAdmin();
    const currentKey = getCurrentMonthKey();
    const currentTransactions = transactions.filter(function (transaction) {
        return getMonthKeyFromDate(transaction.date) === currentKey;
    });

    const income = currentTransactions
        .filter(function (transaction) { return transaction.type === "income"; })
        .reduce(function (total, transaction) { return total + Number(transaction.amount || 0); }, 0);

    const expenses = currentTransactions
        .filter(function (transaction) { return transaction.type === "expense"; })
        .reduce(function (total, transaction) { return total + Number(transaction.amount || 0); }, 0);

    setText("adminUserCount", users.length);
    setText("adminTransactionCount", currentTransactions.length);
    setText("adminIncomeTotal", adminCurrency(income));
    setText("adminExpenseTotal", adminCurrency(expenses));

    renderAdminUsers(users);
    renderAdminTransactions(currentTransactions);
    renderAdminBudgets();
    renderAdminCategories();
}

function setText(id, value) {
    const element = document.getElementById(id);

    if (element) {
        element.textContent = value;
    }
}

function updateAdminDateTime() {
    const element = document.getElementById("adminDateTime");

    if (!element) {
        return;
    }

    const now = new Date();
    element.textContent = now.toLocaleDateString("en-PK", {
        day: "2-digit",
        month: "short",
        year: "numeric"
    }) + " · " + now.toLocaleTimeString("en-PK", {
        hour: "2-digit",
        minute: "2-digit"
    });
}

function getAdminUsers() {
    const users = adminReadJson("campusCoinUsers", []);
    const currentUser = adminReadJson("campusCoinUser", null);
    const result = Array.isArray(users) ? users.slice() : [];

    if (currentUser && currentUser.email) {
        const exists = result.some(function (user) {
            return user.email === currentUser.email;
        });

        if (!exists) {
            result.push(currentUser);
        }
    }

    if (result.length === 0) {
        result.push({
            name: "Campus Coin Student",
            email: "student@campuscoin.com"
        });
    }

    return result;
}

function getAllTransactionsForAdmin() {
    const current = adminReadJson("campusCoinAllTransactions", []);
    const history = adminReadJson("campusCoinTransactionHistory", []);
    const combined = [];
    const seen = {};

    current.concat(history).forEach(function (transaction) {
        if (!transaction || transaction.id === undefined) {
            return;
        }

        if (!seen[String(transaction.id)]) {
            seen[String(transaction.id)] = true;
            combined.push(transaction);
        }
    });

    return combined.sort(function (a, b) {
        return String(b.date || "").localeCompare(String(a.date || ""));
    });
}

function renderAdminUsers(users) {
    const table = document.getElementById("adminUsersTable");

    if (!table) {
        return;
    }

    table.innerHTML = "";

    users.forEach(function (user) {
        const row = document.createElement("tr");
        row.innerHTML = "<td><strong>" + escapeAdminHtml(user.name || "Student") + "</strong></td>" +
            "<td>" + escapeAdminHtml(user.email || "—") + "</td>" +
            "<td><span class=\"status-pill\">Active</span></td>";
        table.appendChild(row);
    });
}

function renderAdminTransactions(transactions) {
    const table = document.getElementById("adminTransactionsTable");

    if (!table) {
        return;
    }

    table.innerHTML = "";

    const items = transactions.slice(0, 8);

    if (items.length === 0) {
        table.innerHTML = "<tr><td colspan=\"4\" class=\"empty-cell\">No transactions this month.</td></tr>";
        return;
    }

    items.forEach(function (transaction) {
        const row = document.createElement("tr");
        const typeClass = transaction.type === "income" ? "income-text" : "expense-text";
        row.innerHTML = "<td><strong>" + escapeAdminHtml(transaction.title || "Transaction") + "</strong></td>" +
            "<td class=\"" + typeClass + "\">" + escapeAdminHtml(transaction.type || "") + "</td>" +
            "<td>" + adminCurrency(transaction.amount) + "</td>" +
            "<td>" + escapeAdminHtml(transaction.date || "—") + " " + escapeAdminHtml(formatTime(transaction.time || "")) + "</td>";
        table.appendChild(row);
    });
}

function renderAdminBudgets() {
    const container = document.getElementById("adminBudgetList");

    if (!container) {
        return;
    }

    const budgets = typeof getBudgets === "function" ? getBudgets() : [];
    container.innerHTML = "";

    if (budgets.length === 0) {
        container.innerHTML = "<p class=\"muted\">No budgets added for this month.</p>";
        return;
    }

    budgets.forEach(function (budget) {
        const spent = typeof getBudgetSpent === "function" ? getBudgetSpent(budget.category) : 0;
        const percentage = budget.amount > 0 ? Math.min((spent / budget.amount) * 100, 100) : 0;
        const item = document.createElement("div");
        item.className = "admin-budget-item";
        item.innerHTML = "<div><strong>" + escapeAdminHtml(budget.category) + "</strong><span>" + adminCurrency(spent) + " / " + adminCurrency(budget.amount) + "</span></div>" +
            "<div class=\"admin-progress\"><span style=\"width:" + percentage + "%\"></span></div>";
        container.appendChild(item);
    });
}

function renderAdminCategories() {
    const container = document.getElementById("adminCategoryCloud");

    if (!container) {
        return;
    }

    const expense = typeof getBuiltInCategories === "function" ? getBuiltInCategories("expense") : [];
    const income = typeof getBuiltInCategories === "function" ? getBuiltInCategories("income") : [];
    const custom = adminReadJson("campusCoinCategories", []);
    const names = expense.concat(income).concat(custom.map(function (category) { return category.name; }));

    container.innerHTML = "";

    names.forEach(function (name) {
        const tag = document.createElement("span");
        tag.className = "admin-category-tag";
        tag.textContent = name;
        container.appendChild(tag);
    });
}

function escapeAdminHtml(value) {
    return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/\"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
