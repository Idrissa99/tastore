/*
 * Design system — point d'entrée unique.
 * Les pages importent uniquement depuis "@/components/ui" (ou le chemin relatif
 * équivalent) : aucun style n'est défini en dehors de cette bibliothèque.
 */

export { default as Button, ButtonLink, IconButton, buttonClasses } from './Button';
export { Input, Select, Textarea, SearchInput, Checkbox, RadioCard, Field } from './Input';
export { default as Card } from './Card';
export { Badge, StatusBadge } from './Badge';
export { default as Alert } from './Alert';
export { default as Avatar, AvatarName } from './Avatar';
export { default as EmptyState, ErrorState } from './EmptyState';
export {
  default as Skeleton,
  SkeletonText,
  SkeletonCard,
  SkeletonGrid,
  SkeletonRows,
  SkeletonStats,
} from './Skeleton';
export { ProgressBar, ProgressCircle, ProgressSummary } from './Progress';
export { Tabs, SegmentedControl, Stepper } from './Tabs';
export { default as Dropdown, DropdownItem, DropdownLink, DropdownLabel, DropdownSeparator, DropdownTrigger } from './Dropdown';
export { default as Modal } from './Modal';
export { default as Drawer } from './Drawer';
export { default as ConfirmationDialog } from './ConfirmationDialog';
export { default as Pagination, normalizePagination } from './Pagination';
export { Table, THead, TH, TBody, TR, TD, DataTable } from './Table';
export { default as PageHeader, SectionHeader, Breadcrumb } from './PageHeader';
export { default as StatCard, MiniStat } from './StatCard';
export { default as ProductImage, mediaUrl, productImage } from './ProductImage';
export { default as ToastViewport } from './ToastViewport';
