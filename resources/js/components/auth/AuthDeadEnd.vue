<script setup>
import AuthLayout from "@/components/auth/AuthLayout.vue";

// Screen 7: a dead-end for an expired or invalid link. One sentence on what
// happened, exactly one action. Reused for invalid invitations and expired
// reset links so the whole system speaks with one voice.
defineProps({
  title: { type: String, default: "This link has expired" },
  body: { type: String, required: true },
  actionLabel: { type: String, default: "Back to sign in" },
  actionTo: { type: String, default: "/login" },
});
</script>

<template>
  <AuthLayout>
    <h1 class="auth-h1">{{ title }}</h1>
    <p class="auth-sub">{{ body }}</p>
    <router-link :to="actionTo" class="auth-link mt-8 inline-block">{{ actionLabel }}</router-link>
  </AuthLayout>
</template>
