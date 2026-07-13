import { createRouter, createWebHistory } from "vue-router";
import { useAuthStore } from "@/stores/auth";

const routes = [
  {
    path: "/",
    redirect: "/leads",
  },
  {
    path: "/login",
    name: "login",
    component: () => import("@/pages/Login.vue"),
  },
  {
    path: "/leads",
    name: "leads",
    component: () => import("@/pages/Leads.vue"),
    meta: { requiresAuth: true },
  },
  {
    path: "/leads/:id",
    name: "lead-detail",
    component: () => import("@/pages/LeadDetail.vue"),
    meta: { requiresAuth: true },
    props: true,
  },
  {
    path: "/clients/:id",
    name: "client-detail",
    component: () => import("@/pages/ClientDetail.vue"),
    meta: { requiresAuth: true },
    props: true,
  },
  {
    path: "/settings",
    name: "settings",
    component: () => import("@/pages/Settings.vue"),
    meta: { requiresAuth: true, adminOnly: true },
  },
  {
    path: "/analytics",
    name: "analytics",
    component: () => import("@/pages/Analytics.vue"),
    meta: { requiresAuth: true, adminOnly: true },
  },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
});

// Client-side gate only — the API enforces the real boundary (role:admin
// middleware, 401/403 on every protected route). This just avoids flashing
// admin-only UI at a bidder before redirecting them away.
router.beforeEach((to) => {
  const auth = useAuthStore();

  if (to.meta.requiresAuth && !auth.token) {
    return { name: "login" };
  }

  if (to.meta.adminOnly && !auth.isAdmin) {
    return { name: "leads" };
  }

  if (to.name === "login" && auth.token) {
    return { name: "leads" };
  }

  return true;
});

export default router;
