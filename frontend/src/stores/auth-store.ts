"use client";

import { create } from "zustand";
import { persist } from "zustand/middleware";
import type { User } from "@/lib/types";

interface AuthState {
  token: string | null;
  user: User | null;
  hasHydrated: boolean;
  setAuth: (token: string, user: User) => void;
  setUser: (user: User) => void;
  logout: () => void;
  setHasHydrated: (value: boolean) => void;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      token: null,
      user: null,
      hasHydrated: false,
      setAuth: (token, user) => set({ token, user }),
      setUser: (user) => set({ user }),
      logout: () => set({ token: null, user: null }),
      setHasHydrated: (value) => set({ hasHydrated: value }),
    }),
    {
      name: "skillleo-auth",
      partialize: (state) => ({ token: state.token, user: state.user }),
      // The callback receives the hydrated state itself — call the setter
      // through it, not through the outer `useAuthStore` binding, which is
      // still mid-assignment (TDZ) the first time this can fire.
      onRehydrateStorage: () => (state) => {
        state?.setHasHydrated(true);
      },
    },
  ),
);

export function isAdmin(user: User | null): boolean {
  return user?.role === "admin";
}
