#!/usr/bin/env python3
"""Génère les icônes du lanceur Android à partir de la même géométrie que le
logo Dart (`TontineMark`).

Le glyphe et le dégradé sont REDESSINÉS ici plutôt que repris d'une image :
l'icône du lanceur et le logo de l'application doivent être le même dessin,
et deux fichiers maintenus à la main finissent toujours par diverger.

Les couleurs viennent de `app_theme.dart` :
  primary500 #14B8A6   primary800 #115E59   blanc #FFFFFF

Sorties :
  - mipmap-*dpi/ic_launcher.png          icône héritée (avant Android 8)
  - mipmap-*dpi/ic_launcher_foreground.png  couche avant du masque adaptatif
  - mipmap-*dpi/ic_launcher_round.png    variante ronde
  - mipmap-anydpi-v26/ic_launcher.xml    icône adaptative
"""

from pathlib import Path

from PIL import Image, ImageDraw

RES = Path(__file__).resolve().parents[1] / "android/app/src/main/res"

PRIMARY_500 = (0x14, 0xB8, 0xA6)
PRIMARY_800 = (0x11, 0x5E, 0x59)
WHITE = (255, 255, 255)

# Densités Android : dossier -> dimension de l'icône héritée en pixels.
DENSITIES = {
    "mdpi": 48,
    "hdpi": 72,
    "xhdpi": 96,
    "xxhdpi": 144,
    "xxxhdpi": 192,
}

# L'icône adaptative travaille sur une grille de 108 dp, dont 66 dp sont
# visibles après masquage : le glyphe doit tenir dans cette zone sûre.
ADAPTIVE_SCALE = 108 / 48

# Facteur de suréchantillonnage. Tracer un trait de 3 px directement sur une
# icône de 48 px donne des bords dentelés et des extrémités arrondies qui
# degeneres en taches : on dessine quatre fois plus grand, puis on réduit en
# LANCZOS, qui reconstruit des bords lisses.
SUPERSAMPLE = 4


def gradient(size, start, end):
    """Dégradé diagonal, comme `LinearGradient(topLeft, bottomRight)`.

    Construit par bandes : une interpolation pixel par pixel en Python serait
    inutilement lente pour les 432 px de l'icône adaptative.
    """
    image = Image.new("RGB", (size, size))
    draw = ImageDraw.Draw(image)
    span = float(size - 1)

    # Une bande par ligne : le dégradé ne dépend que de (x + y) / 2.
    for y in range(size):
        row = []
        for x in range(size):
            t = ((x + y) / 2) / span
            row.append(tuple(round(start[i] + (end[i] - start[i]) * t) for i in range(3)))
        draw.line([(0, y), (size - 1, y)], fill=row[-1])
        # Le dégradé est oblique : chaque ligne doit contenir toutes les teintes.
        for x, colour in enumerate(row):
            draw.point((x, y), fill=colour)

    return image


def _stroke(layer, points, colour, width):
    """Trace un trait à extrémités arrondies.

    Pillow n'a pas d'équivalent de `StrokeCap.round` : les bouts sont des
    disques de rayon `width / 2`, exactement ce que Flutter dessine.
    """
    pen = ImageDraw.Draw(layer)

    # Pillow exige des entiers : le suréchantillonnage rend l'arrondi
    #<Class 'int'> indolore, l'erreur restant bien en dessous d'un demi-pixel.
    points = [(round(x), round(y)) for x, y in points]
    width = max(1, round(width))

    pen.line(points, fill=colour, width=width, joint="curve")

    radius = width / 2
    for x, y in points:
        pen.ellipse([x - radius, y - radius, x + radius, y + radius], fill=colour)


def draw_mark(image, box, colour, stroke_ratio=0.105):
    """Dessine le glyphe « A / toit » de `TontineMark`, dans [box].

    Le dessin se fait sur une surface `SUPERSAMPLE` fois plus grande, puis est
    réduit : c'est ce qui rend le glyphe net à 48 px.
    """
    left, top, right, bottom = box
    side = right - left
    factor = SUPERSAMPLE

    canvas = Image.new("RGBA", (image.width * factor, image.height * factor), (0, 0, 0, 0))

    def at(x, y):
        return ((left + side * x) * factor, (top + side * y) * factor)

    stroke = side * stroke_ratio * factor

    # Le toit : deux traits qui montent vers le sommet et redescendent.
    _stroke(canvas, [at(0.28, 0.60), at(0.50, 0.28), at(0.72, 0.60)], colour, stroke)
    # La traverse, qui achève la lettre.
    _stroke(canvas, [at(0.365, 0.47), at(0.635, 0.47)], colour, stroke)
    # Le socle : la réserve posée sous le toit.
    _stroke(canvas, [at(0.34, 0.76), at(0.66, 0.76)], colour, stroke * 0.8)

    mark = canvas.resize((image.width, image.height), Image.LANCZOS)

    return Image.alpha_composite(image.convert("RGBA"), mark)


def legacy_icon(size):
    """Icône héritée : le carreau dégradé, arrondi, avec le glyphe centré."""
    # Le glyphe occupe les 62 % du carreau, comme dans le logo de l'app.
    inset = side = size * 0.19
    box = (inset, inset, size - inset, size - inset)

    canvas = gradient(size, PRIMARY_500, PRIMARY_800).convert("RGBA")

    # Coins arrondis : l'icône héritée n'est pas masquée par le lanceur sur
    # Android 7 et moins, il faut donc providing le carve-out nous-mêmes.
    mask = Image.new("L", (size, size), 0)
    ImageDraw.Draw(mask).rounded_rectangle([0, 0, size - 1, size - 1], radius=size * 0.22, fill=255)
    canvas.putalpha(mask)

    return draw_mark(canvas, box, WHITE)


def adaptive_layers(size):
    """Fond et avant de l'icône adaptative.

    Le glyphe est réduit à la zone sûre : une icône trop large est rognée par
    le masque du lanceur (cercle, carré arrondi ou « pilule » selon le fabricant).
    """
    background = gradient(size, PRIMARY_500, PRIMARY_800).convert("RGBA")

    # 66/108 de la grille, centré — le reste serait rogné par le masque.
    glyph = size * 66 / 108
    inset = (size - glyph) / 2

    # `draw_mark` RETOURNE l'image composée : l'affecter est indispensable, un
    # appel dont le résultat est ignoré laisse un calque entièrement vide, donc
    # une icône adaptative sans glyphe.
    foreground = draw_mark(
        Image.new("RGBA", (size, size), (0, 0, 0, 0)),
        (inset, inset, inset + glyph, inset + glyph),
        WHITE,
    )

    return background, foreground


def round_icon(size):
    """Variante ronde, pour les lanceurs qui la demandent explicitement."""
    canvas = gradient(size, PRIMARY_500, PRIMARY_800).convert("RGBA")

    mask = Image.new("L", (size * SUPERSAMPLE, size * SUPERSAMPLE), 0)
    ImageDraw.Draw(mask).ellipse([0, 0, size * SUPERSAMPLE - 1, size * SUPERSAMPLE - 1], fill=255)
    canvas.putalpha(mask.resize((size, size), Image.LANCZOS))

    inset = size * 0.24
    return draw_mark(canvas, (inset, inset, size - inset, size - inset), WHITE)


def main():
    for folder, legacy_size in DENSITIES.items():
        target = RES / f"mipmap-{folder}"
        target.mkdir(parents=True, exist_ok=True)

        legacy_icon(legacy_size).save(target / "ic_launcher.png")
        round_icon(legacy_size).save(target / "ic_launcher_round.png")

        adaptive_size = round(legacy_size * ADAPTIVE_SCALE)
        background, foreground = adaptive_layers(adaptive_size)
        background.save(target / "ic_launcher_background.png")
        foreground.save(target / "ic_launcher_foreground.png")

        print(f"  mipmap-{folder}: {legacy_size}px héritée, {adaptive_size}px adaptative")

    # L'icône adaptative : le système masque les calques, il ne faut donc pas
    # de coin arrondi dans le PNG — le masque du fabricant s'en charge.
    anydpi = RES / "mipmap-anydpi-v26"
    anydpi.mkdir(parents=True, exist_ok=True)
    (anydpi / "ic_launcher.xml").write_text(
        '<?xml version="1.0" encoding="utf-8"?>\n'
        '<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">\n'
        '    <background android:drawable="@mipmap/ic_launcher_background" />\n'
        '    <foreground android:drawable="@mipmap/ic_launcher_foreground" />\n'
        "</adaptive-icon>\n",
        encoding="utf-8",
    )
    (anydpi / "ic_launcher_round.xml").write_text(
        (anydpi / "ic_launcher.xml").read_text(encoding="utf-8"),
        encoding="utf-8",
    )
    print("  mipmap-anydpi-v26: icône adaptative")


if __name__ == "__main__":
    main()
