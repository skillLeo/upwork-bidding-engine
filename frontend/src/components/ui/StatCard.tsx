import type { LucideIcon } from "lucide-react";
import { Card } from "./Card";
import { cn } from "@/lib/utils";

export function StatCard({
  label,
  value,
  icon: Icon,
  hint,
  trend,
}: {
  label: string;
  value: string | number;
  icon?: LucideIcon;
  hint?: string;
  trend?: "up" | "down" | "neutral";
}) {
  return (
    <Card className="p-4">
      <div className="flex items-start justify-between">
        <p className="text-xs font-semibold uppercase tracking-wide text-text-secondary">
          {label}
        </p>
        {Icon && <Icon className="h-4 w-4 text-text-tertiary" strokeWidth={1.75} />}
      </div>
      <p className="mt-2 text-2xl font-semibold text-text-primary">{value}</p>
      {hint && (
        <p
          className={cn(
            "mt-1 text-xs",
            trend === "up" && "text-success",
            trend === "down" && "text-danger",
            (!trend || trend === "neutral") && "text-text-tertiary",
          )}
        >
          {hint}
        </p>
      )}
    </Card>
  );
}
