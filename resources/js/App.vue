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
const showNav = computed(() => route.name !== "login" && !route.name?.startsWith("platform-"));

onMounted(async () => {
  useBrandingStore().fetch();
  initLeadAlerts();
  initNotifications();

  // Rehydrates the impersonation banner after a page refresh — the
  // persisted token alone doesn't say whether IT is an impersonation
  // token; only /me (reading the token's own row) knows.
  if (auth.token && !auth.impersonating) {
    try {
      const res = await apiClient.get("/me");
      if (res.data.data.impersonating) {
        auth.setImpersonatingFromMe(res.data.data.impersonating);
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
