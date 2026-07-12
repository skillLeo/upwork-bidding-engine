import { cn, initials } from "@/lib/utils";

const sizeClasses = {
  sm: "h-7 w-7 text-[11px]",
  md: "h-9 w-9 text-sm",
  lg: "h-14 w-14 text-lg",
};

export function Avatar({
  name,
  size = "md",
  className,
}: {
  name: string;
  size?: keyof typeof sizeClasses;
  className?: string;
}) {
  return (
    <div
      className={cn(
        "flex shrink-0 items-center justify-center rounded-full bg-primary-tint font-semibold text-primary",
        sizeClasses[size],
        className,
      )}
      title={name}
    >
      {initials(name)}
    </div>
  );
}
