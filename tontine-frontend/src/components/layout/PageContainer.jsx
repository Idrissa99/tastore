/**
 * Conteneur de page des zones publiques (accueil, catalogue, tontines).
 * Garantit une largeur de lecture confortable et des gouttières homogènes
 * sur mobile, tablette et grand écran.
 */
export function PageContainer({ children, size = 'default', className = '', ...props }) {
  const widths = {
    narrow: 'max-w-3xl',
    default: 'max-w-7xl',
    wide: 'max-w-[100rem]',
  };

  return (
    <div className={`mx-auto w-full ${widths[size] || widths.default} px-5 py-8 sm:px-8 sm:py-12 lg:px-10 lg:py-14 ${className}`} {...props}>
      {children}
    </div>
  );
}

export default PageContainer;
