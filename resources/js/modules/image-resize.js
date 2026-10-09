/**
 * Réduction d'une image avant téléversement.
 *
 * WordPress ramène de toute façon toute image de plus de 2560 px à cette
 * taille (« big_image_size_threshold ») : envoyer l'original de 6000 px ne
 * fait qu'allonger le transfert et la génération des déclinaisons côté site.
 * À 2048 px, largement assez pour une image d'article, WordPress n'a en plus
 * ni copie « -scaled » ni déclinaison 2048 à produire.
 */

export const MAX_DIMENSION = 2048;

// GIF exclu : un canvas n'en garderait que la première image.
const RESIZABLE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const QUALITY = 0.82;

/**
 * Renvoie un fichier réduit, ou le fichier d'origine s'il est déjà assez
 * petit, non réductible ou si le navigateur ne sait pas le faire.
 */
export async function shrinkImage(file, maxDimension = MAX_DIMENSION) {
    if (!file || !RESIZABLE_TYPES.includes(file.type) || typeof createImageBitmap !== 'function') {
        return file;
    }

    let bitmap;

    try {
        // Orientation EXIF appliquée : une photo de téléphone reste droite.
        bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch {
        return file;
    }

    try {
        const scale = maxDimension / Math.max(bitmap.width, bitmap.height);
        if (scale >= 1) return file;

        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);

        const context = canvas.getContext('2d');
        context.imageSmoothingQuality = 'high';
        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);

        const blob = await new Promise((resolve) => canvas.toBlob(resolve, file.type, QUALITY));

        if (!blob || blob.size >= file.size) return file;

        return new File([blob], file.name, { type: file.type, lastModified: file.lastModified });
    } catch {
        return file;
    } finally {
        bitmap.close?.();
    }
}
