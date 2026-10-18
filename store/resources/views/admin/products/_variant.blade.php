<tr data-row>
    <td><input type="hidden" name="variants[{{ $i }}][id]" value="{{ $v['id'] ?? '' }}"><input name="variants[{{ $i }}][options]" value="{{ $v['options'] ?? '' }}" placeholder="Size=A4; Frame=Black" data-variant-options></td>
    <td><input type="number" step="0.01" min="0" name="variants[{{ $i }}][price]" value="{{ $v['price'] ?? '' }}" class="w-num"></td>
    <td><input type="number" step="0.01" min="0" name="variants[{{ $i }}][sale_price]" value="{{ $v['sale_price'] ?? '' }}" class="w-num"></td>
    <td><input name="variants[{{ $i }}][sku]" value="{{ $v['sku'] ?? '' }}" class="w-sku"></td>
    <td class="center"><input type="checkbox" name="variants[{{ $i }}][in_stock]" value="1" @checked(! empty($v['in_stock']))></td>
    <td><input name="variants[{{ $i }}][image]" value="{{ $v['image'] ?? '' }}" placeholder="products/slug/file.jpg"></td>
    <td><button type="button" class="link danger" data-remove-row>✕</button></td>
</tr>
