<script setup>
import PageContainer from "@/components/layout/PageContainer.vue";
import ProfileBasicInfoSection from "@/components/profile/ProfileBasicInfoSection.vue";
import ProfilePasswordSection from "@/components/profile/ProfilePasswordSection.vue";
import ProfileSecuritySection from "@/components/profile/ProfileSecuritySection.vue";
import ProfileSessionsSection from "@/components/profile/ProfileSessionsSection.vue";
</script>

<template>
  <PageContainer class="max-w-[760px] space-y-4">
    <div>
      <h1 class="text-xl font-semibold text-text-primary">Your profile</h1>
      <p class="text-sm text-text-secondary">Manage your name, email, photo, and sign-in security.</p>
    </div>
    <ProfileBasicInfoSection />
    <ProfilePasswordSection />
    <ProfileSecuritySection />
    <ProfileSessionsSection />
  </PageContainer>
</template>
