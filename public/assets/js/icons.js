/*
 * FILE PURPOSE: Browser-side behavior for Icons.
 * DATA FLOW: A rendered page loads this script after the HTML exists. It finds elements through documented selectors/data attributes and enhances interaction without owning server data.
 */

// WHAT: renders every `<i data-lucide="...">` placeholder in the HTML into
//       a real inline SVG icon, using the Lucide icon library.
// WHY:  loaded from a CDN via `@latest` — convenient for now, but that
//       means a future Lucide release could change icon names/output
//       without warning and break icons sitewide with no code change here.
//       Pinning to an exact version (e.g. .../lucide@0.460.0/+esm) removes
//       that risk once this project needs to be more stable.
import {
  createIcons,
  HouseHeart,
  ShoppingCart,
  Store,
  UserRoundPlus,
  UserRoundArrowLeft,
  UserRound,
  AlignStartVertical,
  AlignStartHorizontal,
  X,
  Search,
  Menu,
  ArrowRight,
  ArrowLeft,
  ArrowUpRight,
  Sparkles,
  ShieldCheck,
  Languages,
  ChevronDown,
  Check,
  CreditCard,
  Mail,
  Phone,
  MapPin,
  Send,
  Eye,
  EyeOff,
  KeyRound,
  MailQuestionMark,
  SaveCheck
} from "https://cdn.jsdelivr.net/npm/lucide@latest/+esm";

// WHAT: only icons listed here are available to use as data-lucide="...".
// WHY:  adding a new icon to any page's HTML means also importing and
//       listing it here — Lucide doesn't auto-discover icon names.
createIcons({
  icons: {
    HouseHeart,
    ShoppingCart,
    Store,
    UserRoundPlus,
    UserRoundArrowLeft,
    UserRound,
    AlignStartVertical,
    AlignStartHorizontal,
    X,
    Search,
    Menu,
    ArrowRight,
    ArrowLeft,
    ArrowUpRight,
    Sparkles,
    ShieldCheck,
    Languages,
    ChevronDown,
    Check,
    CreditCard,
    Mail,
    Phone,
    MapPin,
    Send,
    Eye,
    EyeOff,
    KeyRound,
    MailQuestionMark,
    SaveCheck
  }
});
