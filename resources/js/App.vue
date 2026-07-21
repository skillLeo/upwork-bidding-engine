<script setup>
import { computed, onMounted } from "vue";
import { useRoute } from "vue-router";
import { Toaster } from "vue-sonner";
import NavBar from "@/components/layout/NavBar.vue";
import AiProgressToast from "@/components/ui/AiProgressToast.vue";
import { useBrandingStore } from "@/stores/branding";
import { initLeadAlerts } from "@/stores/leadAlerts";

const route = useRoute();
const showNav = computed(() => route.name !== "login");

onMounted(() => {
  useBrandingStore().fetch();
  initLeadAlerts();
});
</script>

<template>
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
