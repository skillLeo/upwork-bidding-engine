"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { login } from "@/lib/auth";
import { apiErrorMessage } from "@/lib/api-client";
import { Button } from "@/components/ui/Button";
import { Input, Label, FieldError } from "@/components/ui/Input";
import { useAuthStore } from "@/stores/auth-store";

const schema = z.object({
  email: z.string().email("Enter a valid email address."),
  password: z.string().min(1, "Password is required."),
});

type FormValues = z.infer<typeof schema>;

export default function LoginPage() {
  const router = useRouter();
  const token = useAuthStore((s) => s.token);
  const hasHydrated = useAuthStore((s) => s.hasHydrated);
  const [submitting, setSubmitting] = React.useState(false);

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  React.useEffect(() => {
    if (hasHydrated && token) {
      router.replace("/leads");
    }
  }, [hasHydrated, token, router]);

  async function onSubmit(values: FormValues) {
    setSubmitting(true);
    try {
      await login(values.email, values.password);
      toast.success("Welcome back.");
      router.replace("/leads");
    } catch (error) {
      toast.error(apiErrorMessage(error, "Could not log in."));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="flex min-h-screen flex-1 flex-col items-center justify-center bg-bg px-4 py-12">
      <div className="mb-8 flex items-center gap-2 text-2xl font-bold text-primary">
        <span className="flex h-10 w-10 items-center justify-center rounded-md bg-primary text-base font-bold text-white">
          SL
        </span>
        SkillLeo
      </div>

      <div className="w-full max-w-sm rounded-card border border-border bg-surface p-8 shadow-card">
        <h1 className="text-xl font-semibold text-text-primary">Sign in</h1>
        <p className="mt-1 text-sm text-text-secondary">Bidding Engine dashboard</p>

        <form onSubmit={handleSubmit(onSubmit)} className="mt-6 space-y-4" noValidate>
          <div>
            <Label htmlFor="email">Email</Label>
            <Input
              id="email"
              type="email"
              autoComplete="email"
              placeholder="you@company.com"
              {...register("email")}
            />
            <FieldError>{errors.email?.message}</FieldError>
          </div>
          <div>
            <Label htmlFor="password">Password</Label>
            <Input
              id="password"
              type="password"
              autoComplete="current-password"
              placeholder="••••••••"
              {...register("password")}
            />
            <FieldError>{errors.password?.message}</FieldError>
          </div>
          <Button type="submit" className="w-full" size="lg" loading={submitting}>
            Sign in
          </Button>
        </form>
      </div>

      <div className="mt-6 max-w-sm rounded-card border border-border bg-white/70 px-4 py-3 text-center text-xs text-text-tertiary">
        Seeded demo accounts — <span className="font-mono">admin@skillleo.test</span> /{" "}
        <span className="font-mono">bidder@skillleo.test</span>, password:{" "}
        <span className="font-mono">password</span>
      </div>
    </div>
  );
}
