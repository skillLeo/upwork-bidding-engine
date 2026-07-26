<script setup>
import { computed, onMounted } from "vue";
import { useRoute } from "vue-router";
import { Toaster } from "vue-sonner";
import NavBar from "@/components/layout/NavBar.vue";
import ImpersonationBanner from "@/components/layout/ImpersonationBanner.vue";
import AiProgressToast from "@/components/ui/AiProgressToast.vue";
import { apiClient } from "@/lib/api-client";
import { useAuthStore } from "@/stores/auth";
import { useBrandingStore } from "@/stores/branding";
import { initLeadAlerts } from "@/stores/leadAlerts";
import { initNotifications } from "@/stores/notifications";

const route = useRoute();
const auth = useAuthStore();
// The auth screens are a self-contained, full-bleed system — never the app
// chrome. Platform console has its own shell too.
const AUTH_ROUTES = new Set([
  "login",
  "signup",
  "forgot-password",
  "reset-password",
  "accept-invite",
  "google-callback",
]);
const showNav = computed(
  () => !AUTH_ROUTES.has(route.name) && !route.name?.startsWith("platform-"),
);

onMounted(async () => {
  useBrandingStore().fetch();
  initLeadAlerts();
  initNotifications();

  // Re-read the whole identity on every boot, not just the impersonation
  // flag.
  //
  // The stored user is written once, at sign-in, and used from then on to
  // decide which navigation items and controls exist. So anything that
  // changes server-side afterwards — a role granted, a permission ticked in
  // the Roles matrix, or the workspace the session belongs to — stayed
  // invisible until the person happened to sign out and back in. That is how
  // a workspace owner ended up staring at a page with NO navigation at all:
  // their session had been repointed to their own workspace, they held all
  // 120 permissions there, and the browser was still reading a snapshot
  // taken when they had none.
  //
  // /me is one request against the token's own row, so refreshing it here
  // costs a boot round trip and removes a whole class of "log out and back
  // in and it works" bugs.
  if (auth.token) {
    try {
      const me = (await apiClient.get("/me")).data.data;

      auth.setUser(me);

      if (me.impersonating) {
        auth.setImpersonatingFromMe(me.impersonating);
      }
    } catch {
      // A dead token here is handled by the response interceptor already.
    }
  }
});
</script>

<template>
  <ImpersonationBanner />
  <NavBar v-if="showNav" />
  <main class="flex-1">
    <router-view />
  </main>
  <AiProgressToast />
  <Toaster
    position="top-right"
    theme="light"
    rich-colors
    close-button
    :duration="4500"
    :gap="12"
    :offset="20"
    :toast-options="{
      class: 'skl-toast',
      style: {
        borderRadius: '12px',
        padding: '14px 16px',
        fontSize: '13.5px',
        fontWeight: '500',
        boxShadow:
          '0 16px 40px -16px rgba(16, 24, 40, 0.32), 0 0 0 1px rgba(16, 24, 40, 0.05)',
      },
    }"
  />
</template>
