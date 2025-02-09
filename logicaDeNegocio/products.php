<?php
// Impedir el acceso directo al archivo
defined('shoppingcart') or exit;
// Obtener todas las categorías de la base de datos.
$stmt = $pdo->query('SELECT * FROM categories');
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->query('SELECT option_name, option_value FROM products_options WHERE option_type = "select" OR option_type = "radio" OR option_type = "checkbox" GROUP BY option_name, option_value ORDER BY option_name, option_value ASC');
$stmt->execute();
$product_options = $stmt->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_ASSOC);
// Obtener la categoría 
$category_list = isset($_GET['category']) && $_GET['category'] ? $_GET['category'] : [];
$category_list = is_array($category_list) ? $category_list : [$category_list];
$category_sql = '';
if ($category_list) {
    $category_sql = 'JOIN products_categories pc ON FIND_IN_SET(pc.category_id, :category_list) AND pc.product_id = p.id JOIN categories c ON c.id = pc.category_id';
}
// Obtener las opciones
$options_list = isset($_GET['option']) && $_GET['option'] ? $_GET['option'] : [];
$options_list = is_array($options_list) ? $options_list : [$options_list];
$options_sql = '';
if ($options_list) {
    $options_sql = 'JOIN products_options po ON po.product_id = p.id AND FIND_IN_SET(CONCAT(po.option_name, "-", po.option_value), :option_list)';
}
// Opciones de disponibilidad
$availability_list = isset($_GET['availability']) && $_GET['availability'] ? $_GET['availability'] : [];
$availability_list = is_array($availability_list) ? $availability_list : [$availability_list];
$availability_sql = '';
if ($availability_list) {
    $availability_sql = 'AND (p.quantity > 0 OR p.quantity = -1)';
    if (in_array('out-of-stock', $availability_list)) {
        $availability_sql = 'AND p.quantity = 0';
    }
}
// Obtener precio mínimo
$price_min = isset($_GET['price_min']) && is_numeric($_GET['price_min']) ? $_GET['price_min'] : '';
// Obtener precio máximo
$price_max = isset($_GET['price_max']) && is_numeric($_GET['price_max']) ? $_GET['price_max'] : '';
$price_sql = '';
// buscar el precio minimo
if ($price_min) {
    $price_sql .= ' AND p.price >= :price_min ';
}
// buscar el precio maximo
if ($price_max) {
    $price_sql .= ' AND p.price <= :price_max ';
}
// Obtener la clasificación
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
// Las cantidades de productos 
$num_products_on_each_page = 12;
$current_page = isset($_GET['p']) && is_numeric($_GET['p']) ? (int)$_GET['p'] : 1;
// Ordenar por declaración
$order_by = '';
// Seleccionar productos ordenados por fecha agregada
if ($sort == 'a-z') {
    // Alfabética A-Z
    $order_by = 'ORDER BY p.title ASC';
} elseif ($sort == 'z-a') {
    // Alfabética Z-A
    $order_by = 'ORDER BY p.title DESC';
} elseif ($sort == 'newest') {
    // El más nuevo
    $order_by = 'ORDER BY p.created DESC';
} elseif ($sort == 'oldest') {
    // Más antiguo
    $order_by = 'ORDER BY p.created ASC';
} elseif ($sort == 'highest') {
    // Precio más alto
    $order_by = 'ORDER BY p.price DESC';
} elseif ($sort == 'lowest') {
    // Precio más bajo
    $order_by = 'ORDER BY p.price ASC';
} elseif ($sort == 'popular') {
    // Más popular
    $order_by = 'ORDER BY (SELECT COUNT(*) FROM transactions_items ti WHERE ti.item_id = p.id) DESC';
}
$stmt = $pdo->prepare('SELECT p.*, (SELECT m.full_path FROM products_media pm JOIN media m ON m.id = pm.media_id WHERE pm.product_id = p.id ORDER BY pm.position ASC LIMIT 1) AS img FROM products p ' . $category_sql . ' ' . $options_sql . ' WHERE p.product_status = 1 ' . $price_sql . ' ' . $availability_sql . ' GROUP BY p.id, p.title, p.description, p.price, p.rrp, p.quantity, p.created, p.weight, p.url_slug, p.product_status, p.sku, p.subscription, p.subscription_period, p.subscription_period_type ' . $order_by . ' LIMIT :page,:num_products');
if ($category_list) {
    $stmt->bindValue(':category_list', implode(',', $category_list), PDO::PARAM_STR);
}
if ($options_list) {
    $stmt->bindValue(':option_list', implode(',', $options_list), PDO::PARAM_STR);
}
if ($price_min) {
    $stmt->bindValue(':price_min', $price_min, PDO::PARAM_STR);
}
if ($price_max) {
    $stmt->bindValue(':price_max', $price_max, PDO::PARAM_STR);
}
$stmt->bindValue(':page', ($current_page - 1) * $num_products_on_each_page, PDO::PARAM_INT);
$stmt->bindValue(':num_products', $num_products_on_each_page, PDO::PARAM_INT);
$stmt->execute();
// Busca los productos de la base de datos
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Obtener el número total de productos
$stmt = $pdo->prepare('SELECT COUNT(*) FROM (SELECT p.id FROM products p ' . $category_sql . ' ' . $options_sql . ' WHERE p.product_status = 1  ' . $price_sql . ' ' . $availability_sql . ' GROUP BY p.id) q');
if ($category_list) {
    $stmt->bindValue(':category_list', implode(',', $category_list), PDO::PARAM_STR);
}
if ($options_list) {
    $stmt->bindValue(':option_list', implode(',', $options_list), PDO::PARAM_STR);
}
if ($price_min) {
    $stmt->bindValue(':price_min', $price_min, PDO::PARAM_STR);
}
if ($price_max) {
    $stmt->bindValue(':price_max', $price_max, PDO::PARAM_STR);
}
$stmt->execute();
$total_products = $stmt->fetchColumn();
?>
<?=template_header('Products')?>

<div class="products content-wrapper">

    <h1 class="page-title">Productos</h1>

    <form action="<?=url('index.php?page=products')?>" method="get" class="products-form form">

        <?php if (!rewrite_url): ?>
        <input type="hidden" name="page" value="products">
        <?php endif; ?>

        <div class="products-filters">

            <?php if ($categories): ?>
            <div class="products-filter">
                <span class="filter-title">Categoria</span>
                <div class="filter-options checkbox-list">
                    <?=populate_categories($categories, $category_list)?>
                    <?php if (count($categories) > 4): ?>
                    <a href="#" class="show-more">+ Mostrar más</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="products-filter">
                <span class="filter-title">Disponiblidad</span>
                <div class="filter-options checkbox-list">
                    <label class="checkbox">
                        <input type="checkbox" name="availability[]" value="in-stock"<?=(in_array('in-stock', $availability_list) ? ' checked' : '')?>>
                        Disponible
                    </label>
                    <label class="checkbox">
                        <input type="checkbox" name="availability[]" value="out-of-stock"<?=(in_array('out-of-stock', $availability_list) ? ' checked' : '')?>>
                        Agotado
                    </label>
                </div>
            </div>

            <?php if ($product_options): ?>
            <?php foreach ($product_options as $option_name => $options): ?>
            <div class="products-filter<?=!in_array($option_name, array_map(function($v) { return explode('-', $v)[0]; }, $options_list)) ? ' closed' : ''?>">
                <span class="filter-title"><?=$option_name?></span>
                <div class="filter-options checkbox-list">
                    <?php foreach ($options as $n => $option): ?>
                    <label class="checkbox<?=$n > 4 ? ' hidden' : ''?>">
                        <input type="checkbox" name="option[]" value="<?=$option_name?>-<?=$option['option_value']?>"<?=(in_array($option_name . '-' . $option['option_value'], $options_list) ? ' checked' : '')?>>
                        <?=$option['option_value']?>
                    </label>
                    <?php endforeach; ?>
                    <?php if (count($options) > 4): ?>
                    <a href="#" class="show-more">+ Mostrar Más</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <div class="products-filter">
                <span class="filter-title">Precio</span>
                <div class="filter-options price-range">
                    <input type="number" step=".01" min="0" name="price_min" placeholder="Min" value="<?=htmlspecialchars($price_min, ENT_QUOTES)?>" class="form-input">
                    <span>a</span>
                    <input type="number" step=".01" min="0" name="price_max" placeholder="Max" value="<?=htmlspecialchars($price_max, ENT_QUOTES)?>" class="form-input">
                </div>
            </div>

        </div>

        <div class="products-view">

            <div class="products-header">
                <p><?=$total_products?> Producto<?=$total_products!=1?'s':''?></p>
                <div class="products-form form">
                    <?php if (!rewrite_url): ?>
                    <input type="hidden" name="page" value="products">
                    <?php endif; ?>
                    <label class="sortby form-select" for="sort">
                    Ordenar por:
                        <select name="sort" id="sort">
                            <option value="a-z"<?=($sort == 'a-z' ? ' selected' : '')?>>Alfabéticamente, A-Z</option>
                            <option value="z-a"<?=($sort == 'z-a' ? ' selected' : '')?>>Alfabéticamente, Z-A</option>
                            <option value="newest"<?=($sort == 'newest' ? ' selected' : '')?>>Fecha, Nuevo a Antiguo</option>
                            <option value="oldest"<?=($sort == 'oldest' ? ' selected' : '')?>>Fecha, Antiguo a Nuevo</option>
                            <option value="highest"<?=($sort == 'highest' ? ' selected' : '')?>>Precio, Alto a Bajo</option>
                            <option value="lowest"<?=($sort == 'lowest' ? ' selected' : '')?>>Precio, Bajo a Alto</option>
                            <option value="popular"<?=($sort == 'popular' ? ' selected' : '')?>>Más popular</option>
                        </select>
                    </label>
                </div>
            </div>

            <div class="products-wrapper">
                <?php foreach ($products as $product): ?>
                <a href="<?=url('index.php?page=product&id=' . ($product['url_slug'] ? $product['url_slug']  : $product['id']))?>" class="product<?=$product['quantity']==0?' no-stock':''?>">
                    <?php if (!empty($product['img']) && file_exists($product['img'])): ?>
                    <div class="img">
                        <img src="<?=base_url?><?=$product['img']?>" width="180" height="180" alt="<?=$product['title']?>">
                    </div>
                    <?php endif; ?>
                    <span class="name"><?=$product['title']?></span>
                    <span class="price">
                        <?=currency_code?><?=number_format($product['price'],2)?>
                        <?php if ($product['rrp'] > 0): ?>
                        <span class="rrp"><?=currency_code?><?=number_format($product['rrp'],2)?></span>
                        <?php endif; ?>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>

            <div class="buttons">
                <?php if ($current_page > 1): ?>
                <?php
                $_GET['p'] = $current_page-1;
                $query = http_build_query($_GET);
                ?>
                <a href="?<?=$query?>" class="btn">Prev</a>
                <?php endif; ?>
                <?php if ($total_products > (($current_page+1) * $num_products_on_each_page) - $num_products_on_each_page): ?>
                <?php
                $_GET['p'] = $current_page+1;
                $query = http_build_query($_GET);
                ?>
                <a href="?<?=$query?>" class="btn">Next</a>
                <?php endif; ?>

            </div>

        </div>

    </form>

</div>

<?=template_footer()?>