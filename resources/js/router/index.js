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
    path: "/register",
    name: "signup",
    component: () => import("@/pages/Register.vue"),
  },
  {
    path: "/forgot-password",
    name: "forgot-password",
    component: () => import("@/pages/ForgotPassword.vue"),
  },
  {
    path: "/reset-password",
    name: "reset-password",
    component: () => import("@/pages/ResetPassword.vue"),
  },
  {
    path: "/accept-invite",
    name: "accept-invite",
    component: () => import("@/pages/AcceptInvite.vue"),
  },
  {
    path: "/auth/google/finish",
    name: "google-callback",
    component: () => import("@/pages/GoogleCallback.vue"),
  },
  {
    path: "/profile",
    name: "profile",
    component: () => import("@/pages/Profile.vue"),
    meta: { requiresAuth: true },
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
    meta: { requiresAuth: true, requiresPermission: "settings.view" },
  },
  {
    path: "/health",
    name: "health",
    component: () => import("@/pages/Health.vue"),
    meta: { requiresAuth: true, requiresPermission: "settings.view" },
  },
  {
    path: "/analytics",
    name: "analytics",
    component: () => import("@/pages/Analytics.vue"),
    meta: { requiresAuth: true, requiresPermission: "analytics.view" },
  },
  // The platform console (P5) — a separate area, never linked from the
  // tenant NavBar, reachable only by users holding a platform_role. Gated
  // below by requiresPlatformStaff, not requiresPermission (platform_role
  // is a completely separate namespace from tenant permissions).
  {
    path: "/platform",
    name: "platform-tenants",
    component: () => import("@/pages/platform/PlatformTenants.vue"),
    meta: { requiresAuth: true, requiresPlatformStaff: true },
  },
  {
    path: "/platform/tenants/:id",
    name: "platform-tenant-detail",
    component: () => import("@/pages/platform/PlatformTenantDetail.vue"),
    meta: { requiresAuth: true, requiresPlatformStaff: true },
    props: true,
  },
  {
    path: "/platform/settings",
    name: "platform-settings",
    component: () => import("@/pages/platform/PlatformSettings.vue"),
    meta: { requiresAuth: true, requiresPlatformStaff: true },
  },
  {
    path: "/platform/health",
    name: "platform-health",
    component: () => import("@/pages/platform/PlatformHealth.vue"),
    meta: { requiresAuth: true, requiresPlatformStaff: true },
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
    // Preserve where they were headed so login can return them there.
    return { name: "login", query: to.fullPath !== "/" ? { redirect: to.fullPath } : {} };
  }

  if (to.meta.requiresPermission && !auth.can(to.meta.requiresPermission)) {
    return { name: "leads" };
  }

  if (to.meta.requiresPlatformStaff && !auth.user?.platform_role) {
    return { name: "leads" };
  }

  if (to.name === "login" && auth.token) {
    return { name: "leads" };
  }

  return true;
});

// Remember the last real page the user was on, so a later sign-in (even after
// a deliberate logout on another day) can drop them back exactly where they left.
router.afterEach((to) => {
  if (to.name && to.name !== "login") {
    useAuthStore().rememberRoute(to.fullPath);
  }
});

export default router;
