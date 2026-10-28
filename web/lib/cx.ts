import cn from "clsx";
import { twMerge } from "tailwind-merge";

export function cx(...classes: (string | boolean | undefined | null)[]) {
  return twMerge(cn(...classes));
}
